<?php
// deployment_guide.php
session_start();
require_once __DIR__ . '/includes/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: Login.php");
    exit();
}

$role     = $_SESSION['role'] ?? 'employee';
$is_owner = ($role === 'owner');

if (!$is_owner && $role !== 'admin') {
    $_SESSION['toast_message'] = [
        'type'    => 'warning',
        'title'   => 'Access Denied',
        'message' => 'Only administrators can view the deployment guide.'
    ];
    header("Location: employee_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deployment Guide · Espenida's POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/inventory-ui.css?v=<?= filemtime(__DIR__ . '/assets/css/inventory-ui.css') ?>">
    <link rel="stylesheet" href="assets/css/deployment-guide.css?v=<?= filemtime(__DIR__ . '/assets/css/deployment-guide.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>
<body class="inv-ui">
    <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content dg-page">
        <header class="welcome-banner">
            <div class="welcome-text">
                <h1>Deployment Guide</h1>
                <p>How to set up and run the system on a new computer step by step.</p>
            </div>
            <!-- Opens the print window, where "Save as PDF" is one of the printers -->
            <button type="button" class="dg-print" onclick="window.print()">
                <i class="fas fa-print" aria-hidden="true"></i>Print or save as PDF
            </button>
        </header>

        <div class="dg-layout">
            <div class="dg-main">

                <section class="dg-card dg-intro">
                    <div class="dg-card-head">
                        <h2>Espenida's Recommendation System &amp; POS and Inventory Management</h2>
                        <p>System Transfer &amp; Setup Guide · For Store Owners &amp; Administrators</p>
                        <p class="dg-lead">
                            This guide will walk you through how to move the system to a new computer and get it running again from scratch.
                            <strong>You do not need to be a computer expert</strong> just follow each step carefully and in order.
                        </p>
                        <ul class="dg-facts">
                            <li>Takes about <strong>15–30 minutes</strong></li>
                            <li>Needs an <strong>internet connection</strong></li>
                            <li>Needs <strong>2 GB of free storage space</strong></li>
                        </ul>
                    </div>
                    <p class="dg-note">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <span>Read this guide carefully before transferring the system to a new computer. Make sure the new computer is turned on before you start.</span>
                    </p>
                </section>

                <!-- STEP 1 -->
                <section class="dg-card" id="step-1">
                    <div class="dg-card-head">
                        <span class="dg-label">Step 1</span>
                        <h2>Copy the System Files to a Flash Drive</h2>
                        <p>Think of this like moving documents from one cabinet to another. You will pack up the system files on your current computer, then bring them over to the new one.</p>
                    </div>
                    <ol class="dg-steps">
                        <li><div>
                            On your <strong>current computer</strong>, open <strong>File Explorer</strong> (the folder icon on your taskbar, or press <code>Win + E</code>).
                        </div></li>
                        <li><div>
                            In the address bar at the top, type <code>C:\xampp\htdocs</code> and press <strong>Enter</strong>. You should see a folder called <strong>Capstone</strong> inside.
                        </div></li>
                        <li><div>
                            <strong>Right-click</strong> on the <strong>Capstone</strong> folder → choose <strong>"Send to"</strong> → then click <strong>"Compressed (zipped) folder"</strong>. This creates a file called <code>Capstone.zip</code> in the same location.
                        </div></li>
                        <li><div>
                            Plug in your <strong>flash drive (USB)</strong>. Drag and copy <code>Capstone.zip</code> onto the flash drive. Wait until the copy finishes before unplugging it.
                        </div></li>
                    </ol>
                    <p class="dg-note">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <span><strong>Optional but recommended:</strong> Before zipping, go to <strong>Backup &amp; Export → Download Backup</strong> from the sidebar. Save that file inside the <code>Capstone\Database SQL\</code> folder (replace the old <code>espenida_pos.sql</code> file). This ensures your latest products, sales, and user accounts are included in the transfer.</span>
                    </p>
                </section>

                <!-- STEP 2 -->
                <section class="dg-card" id="step-2">
                    <div class="dg-card-head">
                        <span class="dg-label">Step 2</span>
                        <h2>Install XAMPP on the New Computer</h2>
                        <p>XAMPP is a free program that lets the computer run the system like a web server. You only need to install it once.</p>
                    </div>
                    <ol class="dg-steps">
                        <li><div>
                            On the <strong>new computer</strong>, open a web browser (Chrome, Edge, or Firefox) and go to:
                            <code class="dg-path">https://www.apachefriends.org</code>
                            Click the big <strong>"Download XAMPP for Windows"</strong> button and wait for the file to download.
                        </div></li>
                        <li><div>
                            Open the downloaded file (it will be named something like <code>xampp-windows-x64-8.x.x-installer.exe</code>). Click <strong>Yes</strong> if Windows asks for permission.
                        </div></li>
                        <li><div>
                            A setup wizard will open. Just keep clicking <strong>Next</strong> until it starts installing. Leave all options as they are — do not change the install folder. Installation takes a few minutes.
                        </div></li>
                        <li><div>
                            When it finishes, it will ask if you want to start the <strong>XAMPP Control Panel</strong>. Click <strong>Yes</strong>. You should see a window with a list of services.
                        </div></li>
                        <li><div>
                            Click the <strong>Start</strong> button next to <strong>Apache</strong>, then click <strong>Start</strong> next to <strong>MySQL</strong>. Both should turn <span class="dg-ok">green</span> when running. <strong>You must do this every time you turn on the computer.</strong>
                        </div></li>
                    </ol>
                </section>

                <!-- STEP 3 -->
                <section class="dg-card" id="step-3">
                    <div class="dg-card-head">
                        <span class="dg-label">Step 3</span>
                        <h2>Copy the System Files onto the New Computer</h2>
                    </div>
                    <ol class="dg-steps">
                        <li><div>
                            Plug your <strong>flash drive</strong> into the new computer and copy <code>Capstone.zip</code> to the Desktop (or anywhere easy to find).
                        </div></li>
                        <li><div>
                            <strong>Right click</strong> on <code>Capstone.zip</code> and choose <strong>"Extract All…"</strong>. In the window that opens, change the destination folder to:
                            <code class="dg-path">C:\xampp\htdocs\</code>
                            Then click <strong>Extract</strong>. This places the <code>Capstone</code> folder inside <code>htdocs</code>.
                        </div></li>
                    </ol>
                    <p class="dg-note">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                        <span><strong>How to verify:</strong> Open File Explorer and go to <code>C:\xampp\htdocs\</code>. You should see a folder named <strong>Capstone</strong> inside it. If it's there, you're good!</span>
                    </p>
                </section>

                <!-- STEP 4 -->
                <section class="dg-card" id="step-4">
                    <div class="dg-card-head">
                        <span class="dg-label">Step 4</span>
                        <h2>Set Up the Database (Your System's Data Storage)</h2>
                        <p>The database is where all products, sales, and users are stored. You need to load it into the new computer so the system has something to read from.</p>
                    </div>
                    <ol class="dg-steps">
                        <li><div>
                            Make sure XAMPP is running (Apache and MySQL are green). Then open any browser and go to:
                            <code class="dg-path">http://localhost/phpmyadmin</code>
                            A page with a blue/white interface will open — this is phpMyAdmin, the database manager.
                        </div></li>
                        <li><div>
                            At the top of the page, click the <strong>"Import"</strong> tab.
                        </div></li>
                        <li><div>
                            Under <strong>"File to Import"</strong>, click <strong>"Choose File"</strong>. A file browser will open. Navigate to:
                            <code class="dg-path">C:\xampp\htdocs\Capstone\Database SQL\</code>
                            and select the file <strong>espenida_pos.sql</strong>. Click <strong>Open</strong>.
                        </div></li>
                        <li><div>
                            Scroll all the way down and click the <strong>"Import"</strong> button. Wait — it may take up to a minute. When it finishes, you will see a green message that says <em>"Import has been successfully finished"</em>.
                        </div></li>
                    </ol>
                    <p class="dg-note">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                        <span>On the left side of phpMyAdmin you should now see a database called <strong>espenida_pos</strong>. That means it worked!</span>
                    </p>
                </section>

                <!-- STEP 5 -->
                <section class="dg-card" id="step-5">
                    <div class="dg-card-head">
                        <span class="dg-label">Step 5</span>
                        <h2>Install Python (for Product Recommendations)</h2>
                        <p>Python is a separate program that powers the smart product recommendation feature. If you don't need recommendations, you can skip this step — everything else will still work.</p>
                    </div>
                    <ol class="dg-steps">
                        <li><div>
                            In the browser, go to:
                            <code class="dg-path">https://www.python.org/downloads/</code>
                            Click the big yellow <strong>"Download Python"</strong> button.
                        </div></li>
                        <li><div>
                            Open the downloaded installer. <strong>Before clicking anything else</strong>, look at the very bottom of the installer window for a checkbox that says <strong>"Add Python to PATH"</strong>. <strong>Make sure it is checked (ticked).</strong> This step is very important — without it the recommendations won't work.
                        </div></li>
                        <li><div>
                            Click <strong>"Install Now"</strong> and wait for it to finish. Click <strong>Close</strong> when done.
                        </div></li>
                    </ol>
                    <p class="dg-note is-caution">
                        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                        <span><strong>Forgot to check "Add Python to PATH"?</strong> Run the installer again, choose <strong>"Modify"</strong>, and on the next screen make sure <strong>"Add Python to environment variables"</strong> is checked.</span>
                    </p>
                </section>

                <!-- STEP 6 -->
                <section class="dg-card" id="step-6">
                    <div class="dg-card-head">
                        <span class="dg-label">Step 6</span>
                        <h2>Open the System in Your Browser</h2>
                        <p>Everything is set up! Now let's open the system.</p>
                    </div>
                    <ol class="dg-steps">
                        <li><div>
                            Make sure the XAMPP Control Panel is open and both <strong>Apache</strong> and <strong>MySQL</strong> are still running (green). If not, click <strong>Start</strong> for each.
                        </div></li>
                        <li><div>
                            Open any web browser and type the following in the address bar — exactly as shown — then press <strong>Enter</strong>:
                            <code class="dg-path">http://localhost/Capstone/Login.php</code>
                            The login page of the system will appear.
                        </div></li>
                        <li><div>
                            Log in using the <strong>owner or administrator</strong> username and password.
                        </div></li>
                        <li><div>
                            <strong>(Optional)</strong> To activate the Recommendations feature, click <strong>Recommendations</strong> in the left sidebar. The first time it opens, it will take about 15–30 seconds to start up automatically in the background. You don't need to do anything — just wait.
                        </div></li>
                    </ol>
                    <p class="dg-note">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <span><strong>Pro tip:</strong> Bookmark <code>http://localhost/Capstone/Login.php</code> in your browser so you can open it quickly next time without typing it out.</span>
                    </p>
                </section>

                <!-- Reminders -->
                <section class="dg-card" id="reminders">
                    <div class="dg-card-head">
                        <span class="dg-label">Reminders</span>
                        <h2>Every Time You Use the System</h2>
                    </div>
                    <ul class="dg-rows">
                        <li>Always <strong>open XAMPP first</strong> and start <strong>Apache</strong> and <strong>MySQL</strong> before opening the browser.</li>
                        <li>The system only works on the <strong>same computer where XAMPP is installed</strong>. Other devices on the same Wi-Fi can access it if configured, but that requires extra setup.</li>
                    </ul>
                </section>

                <!-- Troubleshooting -->
                <section class="dg-card" id="help">
                    <div class="dg-card-head">
                        <span class="dg-label">Help</span>
                        <h2>Something Isn't Working?</h2>
                    </div>
                    <dl class="dg-faq">
                        <div>
                            <dt>The page says "localhost refused to connect"</dt>
                            <dd>XAMPP is not running. Open XAMPP Control Panel and start Apache and MySQL.</dd>
                        </div>
                        <div>
                            <dt>The login page opens but shows a database error</dt>
                            <dd>MySQL is not running, or the database was not imported yet. Go back to <a href="#step-4">Step 4</a>.</dd>
                        </div>
                        <div>
                            <dt>"Recommendation Engine Offline"</dt>
                            <dd>Python was not installed correctly or "Add Python to PATH" was not checked. Re-run the Python installer and choose Modify to fix it.</dd>
                        </div>
                    </dl>
                    <p class="dg-note">
                        <i class="fas fa-circle-question" aria-hidden="true"></i>
                        <span><strong>Still stuck?</strong> Contact the system developer or your IT person and show them this guide. The system log files are located at <code>C:\xampp\htdocs\Capstone\ml\logs\ml_server.log</code>.</span>
                    </p>
                </section>

            </div><!-- /.dg-main -->

            <nav class="dg-toc" aria-label="Sections of this guide">
                <p class="dg-toc-title">In this guide</p>
                <ol>
                    <li><a href="#step-1"><span>1</span>Copy the System Files to a Flash Drive</a></li>
                    <li><a href="#step-2"><span>2</span>Install XAMPP on the New Computer</a></li>
                    <li><a href="#step-3"><span>3</span>Copy the System Files onto the New Computer</a></li>
                    <li><a href="#step-4"><span>4</span>Set Up the Database</a></li>
                    <li><a href="#step-5"><span>5</span>Install Python</a></li>
                    <li><a href="#step-6"><span>6</span>Open the System in Your Browser</a></li>
                    <li><a href="#reminders"><span></span>Every Time You Use the System</a></li>
                    <li><a href="#help"><span></span>Something Isn't Working?</a></li>
                </ol>
            </nav>
        </div><!-- /.dg-layout -->
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/responsive.js"></script>
    <script src="assets/js/sidebar-nav.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar     = document.getElementById('sidebar');
            const collapseBtn = document.getElementById('collapseBtn');
            const collapseIcon = document.getElementById('collapseIcon');
            if (collapseBtn && sidebar) {
                collapseBtn.addEventListener('click', function () {
                    sidebar.classList.toggle('collapsed');
                    if (collapseIcon) {
                        collapseIcon.classList.toggle('fa-chevron-left');
                        collapseIcon.classList.toggle('fa-chevron-right');
                    }
                });
            }
        });
    </script>
</body>
</html>
