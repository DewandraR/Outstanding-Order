Monitoring Outstanding SO Dashboard
<p align="center">
<img src="https://www.google.com/search?q=https://raw.githubusercontent.com/mankyau/oso/main/public/Images/KMI.png" width="150" alt="Logo PT Kayu Mabel Indonesia">
</p>

<h3 align="center">PT Kayu Mabel Indonesia</h3>

<p align="center">
A web-based dashboard application for monitoring outstanding Sales Orders (SO), with data synchronized directly from SAP.
</p>

📋 Overview
This application consists of two main components:

Laravel Web Application: A user-friendly dashboard built with Laravel, Tailwind CSS, and Chart.js. It provides visualizations and detailed reports of outstanding SO data.

Python Sync Service: A standalone Python script (api.py) that connects to SAP via RFC (Z_FM_YPPR079_SO), fetches the latest SO data, and stores it in a MySQL database for the Laravel application to consume.

The primary goal is to present complex SAP data in an interactive, easy-to-understand, and visually appealing format for internal company use.

✨ Key Features
📊 Interactive Dashboard: Main overview with key performance indicators (KPIs) like Total Outstanding Value, Overdue SO count, and performance rates.

📈 Data Visualization: Charts displaying outstanding values by location, SO status overview, top customers by value, and top customers with overdue SOs.

📄 Detailed Drill-Down Reports: An interactive table allowing users to drill down from a customer summary to individual SOs and their specific item details.

🔍 Advanced Filtering & Search: Users can filter data by location (Semarang/Surabaya), SO type (Export/Local), and search for specific PO or SO numbers.

🔄 Automated SAP Sync: A scheduled task that runs the Python script periodically to ensure the data on the dashboard is always up-to-date.

🔐 Authentication: Secure login system for authorized users.

🛠️ Technology Stack
Backend (Web): PHP 8.1+, Laravel 10

Frontend: Tailwind CSS, Alpine.js, Chart.js

Data Sync Service: Python 3.8+

Database: MySQL / MariaDB

SAP Integration: pyrfc (Python RFC Connector)

Web Server: Nginx / Apache

🚀 Installation & Setup
Follow these steps to set up the project locally.

Prerequisites
PHP >= 8.1

Composer

Node.js & NPM

Python >= 3.8

MySQL Database

SAP RFC SDK: The SAP NetWeaver RFC SDK libraries must be installed on the machine running the Python script.

1. Clone the Repository
git clone [https://github.com/your-username/your-repository.git](https://github.com/your-username/your-repository.git)
cd your-repository

2. Setup Laravel Application
First, set up the web dashboard.

# Install PHP dependencies
composer install

# Install NPM dependencies
npm install
npm run build

# Create your environment file
cp .env.example .env

# Generate a new application key
php artisan key:generate

Next, open the .env file and configure your database credentials:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=oso_yppr
DB_USERNAME=root
DB_PASSWORD=

Finally, run the database migrations to create the necessary tables for users, etc. (The SO tables will be created by the Python script).

php artisan migrate

3. Setup Python Sync Service
The Python script api.py is responsible for fetching data from SAP.

First, install the required Python packages. It is highly recommended to use a virtual environment.

# Create and activate a virtual environment (optional but recommended)
python -m venv venv
source venv/bin/activate  # On Windows: venv\Scripts\activate

# Install dependencies
pip install pyrfc mysql-connector-python python-dotenv flask

Next, configure the SAP and Database credentials in your .env file. The Python script will read these values.

# --- SAP Credentials ---
SAP_ASHOST=192.168.254.154
SAP_SYSNR=01
SAP_CLIENT=300
SAP_LANG=EN
SAP_USERNAME=auto_email
SAP_PASSWORD=your_sap_password

# --- DB Credentials (already configured above) ---
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=
DB_NAME=oso_yppr

🔄 Data Synchronization
To populate the dashboard with data, you must run the sync script.

Manual Sync (via Command Line)
You can trigger the sync process manually using the api.py script. This is useful for initial setup or testing.

# Sync all data from the `maping` table
python api.py --sync

# Auto Sync all data from the `maping` tablel (so the output is not too verbose)
php artisan schedule:work 2>&1 | Where-Object {$_ -and ($_ -notmatch "No scheduled commands are ready to run")}

# Sync data for a specific plant (werks)
python api.py --sync --werks 2000

# Sync data for a specific plant and order type (auart)
python api.py --sync --werks 3000 --auart ZOR2

The script will:

Connect to SAP.

Fetch data for each WERKS/AUART pair.

TRUNCATE the old so_yppr079_t* tables.

Bulk insert the new data into the MySQL database.

Automated Sync (via Laravel Scheduler)
For production, the data sync is automated using Laravel's Task Scheduler.

A custom Artisan command php artisan yppr:sync is available, which executes the Python script.

To enable automated syncing, add the following Cron entry to your server. This will run the Laravel scheduler every minute, which in turn will execute the sync command based on the schedule defined in app/Console/Kernel.php.

* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1

You can view the schedule and run it manually with:

# See the list of scheduled tasks
php artisan schedule:list

# Run the scheduler manually (useful for testing)
php artisan schedule:work

🖥️ Usage
Start the development server:

php artisan serve

Navigate to http://127.0.0.1:8000.

Register a new user or log in with existing credentials.

Ensure you have run the data sync at least once to see data on the dashboard.

📄 License
This project is open-sourced software licensed under the MIT license.
