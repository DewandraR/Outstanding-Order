# Monitoring Outstanding SO Dashboard

A web-based dashboard application for monitoring outstanding Sales Orders (SO), with data synchronized directly from SAP.

---

## 🚀 Key Features

- 📊 Interactive Dashboard: Main overview with key performance indicators (KPIs) like Total Outstanding Value, Overdue SO count, and performance rates.

- 📈 Data Visualization: Charts displaying outstanding values by location, SO status overview, top customers by value, and top customers with overdue SOs.

- 📄 Detailed Drill-Down Reports: An interactive table allowing users to drill down from a customer summary to individual SOs and their specific item details.

- 🔍 Advanced Filtering & Search: Users can filter data by location (Semarang/Surabaya), SO type (Export/Local), and search for specific PO or SO numbers.

- 🔄 Automated SAP Sync: A scheduled task that runs the Python script periodically to ensure the data on the dashboard is always up-to-date.

- 🔐 Authentication: Secure login system for authorized users.

---

## 🛠️ Technology Stack

| Komponen         | Teknologi                           |
|------------------|-------------------------------------|
| Backend          | Laravel (PHP 8+)                    |
| Frontend         | Blade Template Engine               |
| Styling          | Tailwind CSS                        |
| Build Tools      | Vite / Laravel Mix                  |
| Data Sync        | Python (`api.py`)       |
| Database         | MySQL (atau sejenisnya)             |

---

## 🧑‍💻 Instalasi & Setup

### 1. Clone Repositori

```bash
git clone https://github.com/DewandraR/Outstanding-Order.git
cd Outstanding-Order
```

### 2. Install Dependency
```bash
composer install
npm install
```

### 3. Konfigurasi Environment
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Setting Database (MYSQL)

```bash
#untuk menjalankan migration dan model gunakan script ini
php artisan migrate
#untuk menjalankan UserSeeder gunakan script ini
php artisan db:seed
```

### 5. Jalankan Server Lokal
```bash
php artisan serve
npm run dev
```

### Sinkronisasi Data (SAP → MySQL)
- Jalankan dari root proyek:
```bash
# Sinkronisasi 1 pair WERKS/AUART
python api.py --sync --werks 2000 --auart ZOR3 --timeout 3000

# Sinkronisasi semua pair yang ada di tabel `maping`
python api.py --sync

```

- Mode Server (Opsional)
```bash
python api.py --serve

```

- Laravel Task Scheduling
```bash
# Singkron manual
php artisan yppr:sync

# Singkron Auto
php artisan schedule:work

# Make the output is not too verbose
php artisan schedule:work 2>&1 | Where-Object {$_ -and ($_ -notmatch "No scheduled commands are ready to run")}


# Menjalankan scheduler & melihat daftar jadwal:
php artisan schedule:list
php artisan schedule:run

```
# HOW TO DEPLOY
## LAKUKAN INSTALASI DEPENDENCY DI LOCAL (COMPOSER & NPM)

```bash
# lakukan di lokal
npm install
npm run build

composer install
composer install --optimize-autoloader --no-dev

# konfigurasi .env (pastikan sudah import file sql ke database)
```


> ⚠️ **IMPORTANT:** Jangan lupa jalankan "php artisan schedule:work" atau "php artisan schedule:work 2>&1 | Where-Object {$_ -and ($_ -notmatch "No scheduled commands are ready to run")}"(agar output tidak Verbose) ketika menjalankan atau deploy ke server atau hosting agar data selalu terupdate.
> python akan auto hit ke SAP pada jam 03.00 untuk menghindari kendala mati listrik ketika dini hari 
> Jalankan `npm run dev` setelah mengedit file Tailwind agar style terkompilasi ulang.
