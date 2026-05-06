#!/bin/bash
# ─────────────────────────────────────────────────────────────────
# CrowdEase — Setup Script
# ─────────────────────────────────────────────────────────────────
# Otomasi setup pertama kali untuk anggota tim baru atau saat
# clone fresh repo. Aman dijalankan ulang (idempotent).

set -e  # exit on first error

# Color codes
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'  # no color

step() { echo -e "\n${BLUE}━━━ $1 ━━━${NC}"; }
ok()   { echo -e "${GREEN}✓${NC}  $1"; }
warn() { echo -e "${YELLOW}⚠${NC}  $1"; }
fail() { echo -e "${RED}✗${NC}  $1" && exit 1; }

# Pastikan dijalankan dari folder root project
if [ ! -f "README.md" ] || [ ! -d "backend" ]; then
    fail "Jalankan script ini dari folder root project (yang ada README.md)."
fi

# ─── Cek Prasyarat ───────────────────────────────────────────
step "Memeriksa prasyarat"

command -v php >/dev/null 2>&1 || fail "PHP tidak ditemukan. Install PHP 8.2+ dulu."
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
ok "PHP terpasang: $PHP_VERSION"

command -v composer >/dev/null 2>&1 || fail "Composer tidak ditemukan. Install Composer 2.x dulu."
ok "Composer terpasang: $(composer --version | head -1)"

command -v python3 >/dev/null 2>&1 || fail "Python 3 tidak ditemukan. Install Python 3.10+ dulu."
PYTHON_VERSION=$(python3 -V 2>&1)
ok "Python terpasang: $PYTHON_VERSION"

command -v node >/dev/null 2>&1 || warn "Node.js tidak ditemukan. Diperlukan untuk build asset Laravel (jalankan 'apt install nodejs npm' atau install via nvm)."

if command -v docker >/dev/null 2>&1 && command -v docker-compose >/dev/null 2>&1; then
    ok "Docker & docker-compose terpasang"
    HAS_DOCKER=1
else
    warn "Docker/docker-compose tidak ditemukan. Tetap bisa lanjut, tapi MySQL harus diinstall manual."
    HAS_DOCKER=0
fi

# ─── Setup Database ──────────────────────────────────────────
step "Setup database"

if [ "$HAS_DOCKER" -eq 1 ]; then
    if [ ! -f "docker-compose.yml" ]; then
        fail "docker-compose.yml tidak ditemukan."
    fi
    docker-compose up -d mysql
    ok "MySQL container started (port 3306)"
    echo "    Tunggu beberapa detik untuk MySQL siap..."
    sleep 5
else
    warn "Skip Docker DB. Pastikan MySQL lokal kalian sudah punya database 'crowdease'."
fi

# ─── Setup Backend Laravel ───────────────────────────────────
step "Setup backend Laravel"

if [ ! -f "backend/composer.json" ]; then
    warn "Laravel belum terinstall di backend/. Jalankan setup Laravel sekarang? [y/N]"
    read -r REPLY
    if [[ "$REPLY" =~ ^[Yy]$ ]]; then
        cd backend
        composer create-project laravel/laravel . "11.*" --no-interaction
        cd ..
        ok "Laravel terinstall"
    else
        warn "Skip install Laravel. Run nanti: cd backend && composer create-project laravel/laravel . '11.*'"
    fi
fi

if [ -f "backend/composer.json" ]; then
    cd backend

    if [ ! -f ".env" ] && [ -f ".env.example" ]; then
        cp .env.example .env
        ok "backend/.env dibuat dari .env.example"
    fi

    if [ ! -f "vendor/autoload.php" ]; then
        composer install --no-interaction
        ok "Composer dependencies terinstall"
    fi

    # Generate APP_KEY kalau belum ada
    if grep -q "^APP_KEY=$" .env 2>/dev/null || grep -q "^APP_KEY=base64:$" .env 2>/dev/null; then
        php artisan key:generate
        ok "APP_KEY di-generate"
    fi

    # Install Sanctum (kalau belum)
    if ! grep -q "laravel/sanctum" composer.json; then
        composer require laravel/sanctum --no-interaction
        ok "Laravel Sanctum terinstall"
    fi

    # Install Scribe untuk auto-doc
    if ! grep -q "knuckleswtf/scribe" composer.json; then
        composer require --dev knuckleswtf/scribe --no-interaction
        ok "Scribe (API documentation) terinstall"
    fi

    cd ..
fi

# ─── Setup IoT Simulator ─────────────────────────────────────
step "Setup IoT simulator"

cd iot-simulator

if [ ! -f ".env" ] && [ -f ".env.example" ]; then
    cp .env.example .env
    ok "iot-simulator/.env dibuat dari .env.example"
    warn "Edit iot-simulator/.env, isi CROWDEASE_API_KEY setelah operator membuat API key"
fi

if [ ! -d "venv" ]; then
    python3 -m venv venv
    ok "Python venv dibuat di iot-simulator/venv"
fi

# shellcheck disable=SC1091
source venv/bin/activate
pip install -q -r requirements.txt
ok "Python dependencies terinstall"
deactivate

cd ..

# ─── Selesai ─────────────────────────────────────────────────
step "Setup selesai!"

echo ""
echo "Langkah selanjutnya:"
echo ""
echo "  1. Edit konfigurasi:"
echo "     - backend/.env (DB credentials, APP_URL)"
echo "     - iot-simulator/.env (API key, akan didapat di langkah 4)"
echo ""
echo "  2. Jalankan migrasi & seeder:"
echo "     cd backend && php artisan migrate --seed"
echo ""
echo "  3. Jalankan backend:"
echo "     cd backend && php artisan serve"
echo ""
echo "  4. Login ke dasbor operator → buat API key → paste ke iot-simulator/.env"
echo "     URL: http://localhost:8000/admin"
echo ""
echo "  5. Jalankan simulator (terminal baru):"
echo "     cd iot-simulator && source venv/bin/activate && python simulator.py"
echo ""
echo "  6. Buka aplikasi penumpang: http://localhost:8000"
echo ""
ok "Selamat coding!"
