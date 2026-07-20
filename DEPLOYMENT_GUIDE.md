# Espenida's POS & Inventory Machine Learning System
## Final Deployment Guide

This guide provides step-by-step instructions on how to package, transfer, and run this system on a brand new laptop or computer for your Capstone defense or production use.

---

### Phase 1: Transferring the Files

1. **Zip the Project Directory:**
   On your current laptop, navigate to your root `htdocs` folder (usually `C:\xampp\htdocs\`).
   Right-click the `Capstone1` folder and compress it into a `.zip` file.
   
2. **Move to the New Laptop:**
   Transfer the `Capstone1.zip` file to the new laptop via a flash drive or cloud storage.

---

### Phase 2: Installing Prerequisites on the New Laptop

The new laptop must have both PHP/MySQL and Python installed to run the dual-stack system.

1. **Install XAMPP (for PHP & MySQL):**
   - Download and install [XAMPP](https://www.apachefriends.org/index.html).
   - Open the XAMPP Control Panel and **Start** both the **Apache** and **MySQL** services.
   - Extract your `Capstone1.zip` folder into the `C:\xampp\htdocs\` directory on the new laptop.

2. **Install Python 3.8+ (for the Machine Learning Engine):**
   - Download and install [Python](https://www.python.org/downloads/).
   - **CRITICAL STEP:** During the installation screen, you MUST check the box that says **"Add Python to PATH"** before clicking Install.

---

### Phase 3: Setting Up the Database

You need to recreate the database tables and records on the new machine.

1. Open your browser and go to: `http://localhost/phpmyadmin`
2. Click **New** on the left sidebar to create a new database.
3. Name the database **`espenida_pos`** (all lowercase) and click **Create**.
4. With the `espenida_pos` database selected, click the **Import** tab at the top.
5. Click **Choose File** and navigate to your extracted folder: 
   `C:\xampp\htdocs\Capstone1\Database SQL\espenida_pos_setup.sql`
6. Scroll down and click **Import**. Your database is now populated!

---

### Phase 4: Starting the Python Machine Learning Server

The POS UI will run automatically via Apache, but the AI recommendation capabilities rely on the Python background server.

1. Open a terminal on the new laptop. You can press `Win + R`, type `cmd`, and press Enter.
2. Navigate into the Machine Learning directory by typing:
   ```cmd
   cd C:\xampp\htdocs\Capstone1\ml
   ```
3. **Install the ML Dependencies:**
   Run the following command to download all the AI libraries (this requires internet access):
   ```cmd
   ```
   *(Wait for it to finish installing pandas, scikit-learn, flask, etc.)*

4. **Start the API Server:**
   ```cmd
   python api_server.py
   ```
   *You should see a message saying "[OK] Starting on http://127.0.0.1:5000". Leave this black terminal window open in the background!*

---

### Phase 5: Launching the System

1. Open a modern web browser (Chrome, Edge, Firefox).
2. Type in the following URL:
   `http://localhost/Capstone1/Login.php`
3. Log in using your default Administrator/Owner credentials.
4. Navigate to the **Recommendations** dashboard. The system will automatically link up with the Python terminal window in the background, load the Random Forest model, and analyze your inventory!

> **Troubleshooting Tip:** If the Recommendation dashboard says "Disconnected", immediately check the black Python terminal window to ensure it hasn't crashed or been closed.
