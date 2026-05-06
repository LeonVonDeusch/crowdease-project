#!/usr/bin/env python3
"""
CrowdEase IoT Simulator
=======================

Mensimulasikan perangkat IoT pada armada bus TransJakarta yang mengirim
data jumlah penumpang ke backend CrowdEase secara periodik.

Pola data realistis:
- Jam sibuk pagi (07:00-09:00) dan sore (17:00-19:00) → kepadatan tinggi
- Jam off-peak → kepadatan rendah
- Random walk per kendaraan: tidak ada lompatan ekstrim 5 → 60 dalam 5 detik
- Setiap kendaraan punya baseline berbeda

Cara pakai:
    cp .env.example .env
    # edit .env, isi API_KEY yang valid
    pip install -r requirements.txt
    python simulator.py                  # mode kontinu, ~1 reading/2 detik
    python simulator.py --burst          # kirim sekali untuk semua, lalu exit
    python simulator.py --tick 1         # mode cepat untuk demo
    python simulator.py --duration 60    # jalan 60 detik lalu berhenti

Tekan Ctrl+C untuk menghentikan kapan saja.
"""

import os
import sys
import time
import math
import random
import argparse
import signal
from datetime import datetime, timezone, timedelta
from typing import List, Optional

import requests
from dotenv import load_dotenv


# =============================================================================
# Konfigurasi & Konstanta
# =============================================================================

WIB = timezone(timedelta(hours=7))  # Asia/Jakarta
DEFAULT_BASE_URL = "http://localhost:8000"
DEFAULT_TICK_INTERVAL = 2.0  # detik antar pengiriman
DEFAULT_TIMEOUT = 5.0


class C:
    """ANSI color codes."""
    RESET = "\033[0m"
    BOLD = "\033[1m"
    DIM = "\033[2m"
    GREEN = "\033[32m"
    YELLOW = "\033[33m"
    RED = "\033[31m"
    CYAN = "\033[36m"
    BLUE = "\033[34m"
    MAGENTA = "\033[35m"
    GRAY = "\033[90m"


def disable_colors_if_needed():
    """Disable colors on Windows cmd or non-tty output."""
    if not sys.stdout.isatty() or os.getenv("NO_COLOR"):
        for attr in dir(C):
            if not attr.startswith("_"):
                setattr(C, attr, "")


# =============================================================================
# Time helpers
# =============================================================================

def now_iso() -> str:
    """Return current time in ISO 8601 with WIB offset."""
    return datetime.now(WIB).isoformat(timespec="seconds")


def now_str() -> str:
    """Return current HH:MM:SS for log prefix."""
    return datetime.now(WIB).strftime("%H:%M:%S")


# =============================================================================
# Vehicle model
# =============================================================================

# Sample vehicles untuk default. ID harus match dengan seeder Laravel.
# Operator dapat override via env DEFAULT_VEHICLES atau pass --vehicles N.
DEFAULT_VEHICLES = [
    # (id, plate_number, route_code, capacity)
    (1, "B 7001 TRN", "K1", 60),
    (2, "B 7002 TRN", "K1", 60),
    (3, "B 7011 TRN", "K2", 60),
    (4, "B 7012 TRN", "K2", 80),
    (5, "B 7021 TRN", "K3", 60),
    (6, "B 7031 TRN", "K9", 60),
    (7, "B 7041 TRN", "K13", 80),
]


class Vehicle:
    """Mewakili satu armada bus dengan state penumpang yang ber-evolusi."""

    def __init__(self, vehicle_id: int, plate: str, route_code: str, capacity: int):
        self.vehicle_id = vehicle_id
        self.plate = plate
        self.route_code = route_code
        self.capacity = capacity
        # Setiap kendaraan punya offset baseline unik (±10%)
        self._baseline_offset = random.uniform(-0.10, 0.10)
        # State awal: random load 10-40% kapasitas
        self.passenger_count = random.randint(int(capacity * 0.1), int(capacity * 0.4))

    def _rush_factor(self) -> float:
        """
        Hitung faktor jam sibuk: 0.0 (off-peak) sampai 1.0 (puncak).
        Pakai bell curve di sekitar jam 8 (pagi) dan jam 18 (sore).
        """
        hour = datetime.now(WIB).hour + datetime.now(WIB).minute / 60.0
        # Dua puncak gaussian: pagi (8:00) dan sore (18:00)
        morning = math.exp(-((hour - 8.0) ** 2) / 1.5)
        evening = math.exp(-((hour - 18.0) ** 2) / 1.5)
        return max(morning, evening)

    def tick(self) -> None:
        """
        Advance state by one tick. Random walk menuju target occupancy
        berdasarkan jam.
        """
        rush = self._rush_factor()
        baseline = 0.20 + self._baseline_offset  # ~20% off-peak baseline
        peak_max = 0.95  # mendekati penuh saat puncak

        target_ratio = baseline + (peak_max - baseline) * rush
        target_ratio = max(0.05, min(1.0, target_ratio))
        target_count = int(self.capacity * target_ratio)

        # Random walk: 15% gerak menuju target + noise ±3
        diff = target_count - self.passenger_count
        change = int(diff * 0.15) + random.randint(-3, 3)
        new_count = self.passenger_count + change

        # Clamp ke [0, capacity * 1.05] (boleh sedikit overflow untuk realisme)
        max_count = int(self.capacity * 1.05)
        self.passenger_count = max(0, min(max_count, new_count))

    def to_payload(self) -> dict:
        """Format payload sesuai API contract POST /sensors/readings."""
        return {
            "vehicle_id": self.vehicle_id,
            "passenger_count": self.passenger_count,
            "capacity_at_time": self.capacity,
            "recorded_at": now_iso(),
        }

    def occupancy_ratio(self) -> float:
        return self.passenger_count / self.capacity if self.capacity else 0.0

    def occupancy_level(self) -> str:
        ratio = self.occupancy_ratio()
        if ratio < 0.5:
            return "LOW"
        elif ratio < 0.8:
            return "MED"
        elif ratio <= 1.0:
            return "HIGH"
        else:
            return "OVER"

    def occupancy_color(self) -> str:
        return {
            "LOW": C.GREEN,
            "MED": C.YELLOW,
            "HIGH": C.RED,
            "OVER": C.MAGENTA,
        }[self.occupancy_level()]


# =============================================================================
# Simulator
# =============================================================================

class Simulator:
    def __init__(self, base_url: str, api_key: str, vehicles: List[Vehicle], tick: float):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.vehicles = vehicles
        self.tick = tick
        self.session = requests.Session()
        self.session.headers.update({
            "X-API-Key": api_key,
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "CrowdEase-IoT-Simulator/1.0",
        })
        self.success_count = 0
        self.error_count = 0
        self._stop_requested = False

    def stop(self):
        self._stop_requested = True

    def _log_success(self, v: Vehicle):
        ratio_pct = int(v.occupancy_ratio() * 100)
        print(
            f"{C.GRAY}[{now_str()}]{C.RESET} "
            f"{C.GREEN}✓{C.RESET}  "
            f"{C.BOLD}{v.plate:<11}{C.RESET} "
            f"{C.DIM}{v.route_code:<4}{C.RESET}  "
            f"{v.passenger_count:>3}/{v.capacity:<3}  "
            f"{v.occupancy_color()}{ratio_pct:>3}%  {v.occupancy_level():<4}{C.RESET}",
            flush=True,
        )

    def _log_error(self, v: Vehicle, message: str):
        print(
            f"{C.GRAY}[{now_str()}]{C.RESET} "
            f"{C.RED}✗{C.RESET}  "
            f"{C.BOLD}{v.plate:<11}{C.RESET} "
            f"{C.DIM}{v.route_code:<4}{C.RESET}  "
            f"{C.RED}{message}{C.RESET}",
            flush=True,
        )

    def send_reading(self, v: Vehicle) -> bool:
        """Kirim satu reading ke backend. Return True jika sukses."""
        url = f"{self.base_url}/api/v1/sensors/readings"
        payload = v.to_payload()

        try:
            resp = self.session.post(url, json=payload, timeout=DEFAULT_TIMEOUT)

            if 200 <= resp.status_code < 300:
                self.success_count += 1
                self._log_success(v)
                return True
            else:
                self.error_count += 1
                # Coba ekstrak pesan error dari format response sistem
                try:
                    body = resp.json()
                    err_msg = body.get("error", {}).get("message", resp.text[:80])
                except Exception:
                    err_msg = resp.text[:80] if resp.text else "no body"
                self._log_error(v, f"HTTP {resp.status_code}: {err_msg}")
                return False

        except requests.exceptions.ConnectionError:
            self.error_count += 1
            self._log_error(v, "Backend tidak menjawab (apakah Laravel berjalan?)")
            return False
        except requests.exceptions.Timeout:
            self.error_count += 1
            self._log_error(v, f"Timeout setelah {DEFAULT_TIMEOUT}s")
            return False
        except Exception as e:
            self.error_count += 1
            self._log_error(v, f"{type(e).__name__}: {e}")
            return False

    def run_continuous(self, duration_seconds: Optional[int] = None):
        """
        Jalankan simulator dalam mode kontinu.
        Setiap tick, ambil 1 vehicle (round-robin), update state, dan kirim.
        """
        start = time.monotonic()
        idx = 0

        print_section_header("Memulai simulasi kontinu")
        print(f"{C.DIM}Tekan Ctrl+C untuk berhenti dan melihat ringkasan.{C.RESET}\n")

        while not self._stop_requested:
            if duration_seconds is not None:
                elapsed = time.monotonic() - start
                if elapsed >= duration_seconds:
                    print(f"\n{C.DIM}Durasi {duration_seconds}s tercapai. Berhenti.{C.RESET}")
                    break

            v = self.vehicles[idx % len(self.vehicles)]
            v.tick()
            self.send_reading(v)
            idx += 1

            time.sleep(self.tick)

    def run_burst(self):
        """Kirim satu reading untuk setiap kendaraan, lalu exit."""
        print_section_header("Burst mode: kirim sekali untuk semua kendaraan")
        for v in self.vehicles:
            v.tick()
            self.send_reading(v)
            time.sleep(0.1)  # Beri jeda kecil agar log readable

    def print_summary(self):
        total = self.success_count + self.error_count
        if total == 0:
            return
        print()
        print_section_header("Ringkasan")
        print(f"  Total request : {C.BOLD}{total}{C.RESET}")
        print(f"  Sukses        : {C.GREEN}{self.success_count}{C.RESET}")
        print(f"  Gagal         : {C.RED}{self.error_count}{C.RESET}")
        if total > 0:
            success_rate = (self.success_count / total) * 100
            print(f"  Success rate  : {success_rate:.1f}%")
        print()


# =============================================================================
# UI helpers
# =============================================================================

def print_banner():
    banner = f"""{C.CYAN}
   ____                     _ _____
  / ___|_ __ _____      ____| | ____|__ _ ___  ___
 | |   | '__/ _ \\ \\ /\\ / / _` |  _| / _` / __|/ _ \\
 | |___| | | (_) \\ V  V / (_| | |__| (_| \\__ \\  __/
  \\____|_|  \\___/ \\_/\\_/ \\__,_|_____\\__,_|___/\\___|
{C.RESET}
  {C.DIM}IoT Simulator v1.0  •  Mata Kuliah Teknologi Integrasi Sistem{C.RESET}
"""
    print(banner)


def print_section_header(title: str):
    print(f"\n{C.BLUE}━━━ {title} ━━━{C.RESET}")


def print_config(base_url: str, api_key: str, tick: float, vehicle_count: int,
                 duration: Optional[int]):
    print_section_header("Konfigurasi")
    masked_key = api_key[:8] + "..." if len(api_key) > 8 else "***"
    print(f"  Backend URL    : {C.CYAN}{base_url}{C.RESET}")
    print(f"  API Key        : {C.DIM}{masked_key}{C.RESET}")
    print(f"  Tick interval  : {tick}s antar kendaraan")
    print(f"  Jumlah armada  : {vehicle_count}")
    print(f"  Durasi         : {duration}s" if duration else "  Durasi         : tak terbatas")


def print_vehicles(vehicles: List[Vehicle]):
    print_section_header("Daftar armada disimulasikan")
    for v in vehicles:
        print(f"  {C.BOLD}#{v.vehicle_id:<2}{C.RESET}  "
              f"{v.plate:<11}  {C.DIM}{v.route_code:<4}{C.RESET}  "
              f"kapasitas {v.capacity}")


# =============================================================================
# Main entry point
# =============================================================================

def build_vehicles(count: int) -> List[Vehicle]:
    """Build list of Vehicle instances dari DEFAULT_VEHICLES, dipotong ke count."""
    if count > len(DEFAULT_VEHICLES):
        print(f"{C.YELLOW}⚠  Diminta {count} kendaraan, hanya tersedia {len(DEFAULT_VEHICLES)}. "
              f"Memakai {len(DEFAULT_VEHICLES)}.{C.RESET}")
        count = len(DEFAULT_VEHICLES)
    return [Vehicle(*spec) for spec in DEFAULT_VEHICLES[:count]]


def parse_args():
    parser = argparse.ArgumentParser(
        description="CrowdEase IoT Simulator — kirim data sensor dummy ke backend",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__.split("Cara pakai:")[1] if __doc__ else None,
    )
    parser.add_argument("--tick", type=float, default=DEFAULT_TICK_INTERVAL,
                        help=f"Detik antar pengiriman (default: {DEFAULT_TICK_INTERVAL})")
    parser.add_argument("--duration", type=int, default=None,
                        help="Durasi running dalam detik. Default: tak terbatas")
    parser.add_argument("--vehicles", type=int, default=5,
                        help="Jumlah kendaraan disimulasikan (max 7, default: 5)")
    parser.add_argument("--burst", action="store_true",
                        help="Kirim sekali untuk setiap kendaraan, lalu keluar")
    parser.add_argument("--no-banner", action="store_true",
                        help="Sembunyikan banner ASCII")
    return parser.parse_args()


def main():
    load_dotenv()
    disable_colors_if_needed()
    args = parse_args()

    if not args.no_banner:
        print_banner()

    # Load config dari environment
    base_url = os.getenv("CROWDEASE_BASE_URL", DEFAULT_BASE_URL)
    api_key = os.getenv("CROWDEASE_API_KEY", "").strip()

    if not api_key:
        print(f"{C.RED}ERROR{C.RESET}: variabel CROWDEASE_API_KEY belum diset.")
        print(f"  → Copy .env.example ke .env, lalu isi API key yang valid.")
        print(f"  → API key dibuat operator via dasbor: /admin/api-keys")
        sys.exit(1)

    # Build vehicle list
    vehicles = build_vehicles(args.vehicles)

    print_config(base_url, api_key, args.tick, len(vehicles), args.duration)
    print_vehicles(vehicles)

    sim = Simulator(base_url, api_key, vehicles, args.tick)

    # Tangani Ctrl+C dengan rapi
    def handle_sigint(signum, frame):
        print(f"\n{C.YELLOW}Menerima Ctrl+C, menghentikan simulasi...{C.RESET}")
        sim.stop()

    signal.signal(signal.SIGINT, handle_sigint)

    try:
        if args.burst:
            sim.run_burst()
        else:
            sim.run_continuous(args.duration)
    finally:
        sim.print_summary()


if __name__ == "__main__":
    main()
