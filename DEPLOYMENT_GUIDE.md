# Espenida's POS & Inventory Machine Learning System
## Final Deployment Guide

This guide provides step-by-step instructions on how to package, transfer, and run this system on a brand new laptop or computer for your Capstone defense or production use.

---

### Phase 1: Transferring the Files

1. **Zip the Project Directory:**
   On your current laptop, navigate to your root `htdocs` folder (usually `C:\xampp\htdocs\`).
   Right-click the `Capstone` folder and compress it into a `.zip` file.

2. **Move to the New Laptop:**
   Transfer the `Capstone.zip` file to the new laptop via a flash drive or cloud storage.

> **Tip:** To bring your latest products, sales and users, first sign in as the owner and open **Backup & export → Download backup**, then save the file as `Capstone\Database SQL\espenida_pos.sql` (replacing the old one) before zipping.

> **Using GitHub instead of a zip?** Commit and push first. The new laptop only gets what is on GitHub, including `espenida_pos.sql`.

---

### Phase 2: Installing Prerequisites on the New Laptop

The new laptop must have both PHP/MySQL and Python installed to run the dual-stack system.

1. **Install XAMPP (for PHP & MySQL):**
   - Download and install [XAMPP](https://www.apachefriends.org/index.html).
   - Open the XAMPP Control Panel and **Start** both the **Apache** and **MySQL** services.
   - Extract your `Capstone.zip` folder into the `C:\xampp\htdocs\` directory on the new laptop.

2. **Install Python 3.8+ (for the Machine Learning Engine):**
   - Download and install [Python](https://www.python.org/downloads/).
   - **CRITICAL STEP:** During the installation screen, you MUST check the box that says **"Add Python to PATH"** before clicking Install.

---

### Phase 3: Setting Up the Database

There is only one file to import: **`Database SQL\espenida_pos.sql`**. It creates the `espenida_pos` database by itself and fills in every table and record.

1. Open your browser and go to: `http://localhost/phpmyadmin`
2. Click the **Import** tab at the top (you do not need to create or select a database first).
3. Click **Choose File** and select:
   `C:\xampp\htdocs\Capstone\Database SQL\espenida_pos.sql`
4. Scroll down and click **Import**, then wait for the green *"Import has been successfully finished"* message (it can take up to a minute). The `espenida_pos` database now appears on the left.

> If an `espenida_pos` database already exists on that laptop, importing replaces its tables with the ones in the file.

---

### Phase 4: Installing the Machine Learning Libraries

The recommendation engine is a Python server. You only install its libraries once; the system starts the server by itself whenever it is needed.

> **Automatic:** you can skip this phase. The first time someone opens **Recommendations**, the system installs the libraries by itself (it needs internet and takes a few minutes; the page says "Setting up the recommendation engine" and retries on its own). Doing the steps below just makes that first visit faster.

1. Open a terminal on the new laptop. You can press `Win + R`, type `cmd`, and press Enter.
2. Navigate into the Machine Learning directory by typing:
   ```cmd
   cd C:\xampp\htdocs\Capstone\ml
   ```
3. **Install the ML Dependencies** (this requires internet access):
   ```cmd
   pip install -r requirements.txt
   ```
   *(Wait for it to finish installing pandas, scikit-learn, flask, etc.)*

   > **If you get "'pip' is not recognized"** (this happens on newer Python versions such as 3.14, where the `pip` shortcut is not always added), run pip through Python instead. Try the first command, and if it says Python is not found, the second:
   > ```cmd
   > python -m pip install -r requirements.txt
   > ```
   > ```cmd
   > py -m pip install -r requirements.txt
   > ```
   > Both do exactly the same thing as `pip install -r requirements.txt`. Run them inside the `ml` folder, as in step 2.

There is no need to run `python api_server.py` or keep a terminal open.

---

### Phase 5: Launching the System

1. Open a modern web browser (Chrome, Edge, Firefox).
2. Type in the following URL:
   `http://localhost/Capstone/Login.php`
3. Log in using your default Administrator/Owner credentials.
4. Navigate to the **Recommendations** dashboard. The first visit starts the Python server in the background (about 15 seconds the very first time, a few seconds after that), loads the Random Forest model, and analyzes your inventory.

> **Troubleshooting Tip:** If the Recommendation dashboard says "Recommendation Engine Offline", the page now says why. The usual cause is that Python was installed without "Add python.exe to PATH" (reinstall or modify it and tick that box). Details are in `C:\xampp\htdocs\Capstone\ml\logs\ml_server.log` (server) and `ml\logs\pip_install.log` (library install).
