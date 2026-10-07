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
    <link rel="stylesheet" href="assets/css/inventory-ui.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <style>
        /* ── Page layout ── */
        body.inv-ui {
            display: flex;
            height: 100vh;
            overflow: hidden;
            margin: 0;
            background: var(--canvas);
        }
        .main-content {
            flex: 1;
            overflow-y: auto;
            background: var(--canvas);
        }

        /* ── Guide card ── */
        .guide-wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 20px 60px;
        }
        .guide-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            padding: 36px 40px;
            margin-bottom: 24px;
        }

        /* ── Typography ── */
        .guide-card h2 {
            color: #2c5530;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .guide-card .subtitle {
            color: #61706a;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .guide-card p {
            color: #3b4a41;
            line-height: 1.75;
        }

        /* ── Phase headings ── */
        .phase-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 36px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eaf0ea;
        }
        .phase-badge {
            background: #2c5530;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 3px 10px;
            border-radius: 20px;
            white-space: nowrap;
            text-transform: uppercase;
        }
        .phase-heading h3 {
            color: #2c5530;
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
        }

        /* ── Steps ── */
        .step-list {
            list-style: none;
            padding: 0;
            margin: 0;
            counter-reset: step;
        }
        .step-list > li {
            counter-increment: step;
            display: flex;
            gap: 14px;
            margin-bottom: 18px;
        }
        .step-num {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #eaf0ea;
            color: #2c5530;
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px;
        }
        .step-body {
            flex: 1;
            color: #3b4a41;
            line-height: 1.7;
        }
        .step-body strong {
            color: #16221a;
        }

        /* ── Callouts ── */
        .callout {
            border-radius: 8px;
            padding: 12px 16px;
            margin: 14px 0;
            font-size: 0.9rem;
            line-height: 1.65;
        }
        .callout-tip    { background: #eaf0ea; border-left: 4px solid #2c5530; color: #1f3d23; }
        .callout-warn   { background: #fff8ec; border-left: 4px solid #f0ad4e; color: #7a5300; }
        .callout-danger { background: #fdeceb; border-left: 4px solid #b42318; color: #7a1c14; }
        .callout i { margin-right: 6px; }

        /* ── Code ── */
        .guide-card code {
            background: #f1f3f2;
            color: #b45309;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.88em;
        }
        .cmd-block {
            background: #1e2d22;
            color: #a8d5b0;
            font-family: 'Courier New', monospace;
            font-size: 0.88rem;
            padding: 12px 16px;
            border-radius: 8px;
            margin: 10px 0;
            overflow-x: auto;
            white-space: pre;
        }

        /* ── Download bar ── */
        .download-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #2c5530;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 9px 20px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-download:hover { background: #1f3d23; color: #fff; }

        /* ── Print styles ── */
        @media print {
            .sidebar, .welcome-banner, .download-bar, .mobile-menu-btn, .sidebar-overlay {
                display: none !important;
            }
            body.inv-ui {
                display: block !important;
                height: auto !important;
                overflow: visible !important;
            }
            .main-content {
                overflow: visible !important;
                height: auto !important;
            }
            .guide-card {
                box-shadow: none !important;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="inv-ui">
    <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1>Deployment Guide</h1>
                <p>How to set up and run the system on a new computer step by step.</p>
            </div>
        </div>

        <div class="guide-wrap mt-4">

            <!-- Download bar -->
            <div class="download-bar">
                <p class="text-muted mb-0" style="font-size:0.9rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Read this guide carefully before transferring the system to a new computer.
                </p>
                <button class="btn-download" onclick="window.print()">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </button>
            </div>

            <!-- Main card -->
            <div class="guide-card">
                <h2>Espenida's Recommendation System & POS and Inventory Management</h2>
                <p class="subtitle">System Transfer &amp; Setup Guide · For Store Owners &amp; Administrators</p>

                <p>
                    This guide will walk you through how to move the system to a new computer and get it running again from scratch.
                    <strong>You do not need to be a computer expert</strong> just follow each step carefully and in order.
                    The whole process takes about <strong>15–30 minutes</strong>.
                </p>

                <div class="callout callout-tip">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Before you start:</strong> Make sure the new computer is turned on, connected to the internet, and has at least <strong>2 GB of free storage space</strong>.
                </div>

                <!-- STEP 1 -->
                <div class="phase-heading">
                    <span class="phase-badge">Step 1</span>
                    <h3>Copy the System Files to a Flash Drive</h3>
                </div>
                <p>Think of this like moving documents from one cabinet to another. You will pack up the system files on your current computer, then bring them over to the new one.</p>
                <ul class="step-list">
                    <li>
                        <span class="step-num">1</span>
                        <div class="step-body">
                            On your <strong>current computer</strong>, open <strong>File Explorer</strong> (the folder icon on your taskbar, or press <code>Win + E</code>).
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div class="step-body">
                            In the address bar at the top, type <code>C:\xampp\htdocs</code> and press <strong>Enter</strong>. You should see a folder called <strong>Capstone</strong> inside.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">3</span>
                        <div class="step-body">
                            <strong>Right-click</strong> on the <strong>Capstone</strong> folder → choose <strong>"Send to"</strong> → then click <strong>"Compressed (zipped) folder"</strong>. This creates a file called <code>Capstone.zip</code> in the same location.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">4</span>
                        <div class="step-body">
                            Plug in your <strong>flash drive (USB)</strong>. Drag and copy <code>Capstone.zip</code> onto the flash drive. Wait until the copy finishes before unplugging it.
                        </div>
                    </li>
                </ul>
                <div class="callout callout-warn">
                    <i class="fas fa-star"></i>
                    <strong>Optional but recommended:</strong> Before zipping, go to <strong>Backup &amp; Export → Download Backup</strong> from the sidebar. Save that file inside the <code>Capstone\Database SQL\</code> folder (replace the old <code>espenida_pos.sql</code> file). This ensures your latest products, sales, and user accounts are included in the transfer.
                </div>

                <!-- STEP 2 -->
                <div class="phase-heading">
                    <span class="phase-badge">Step 2</span>
                    <h3>Install XAMPP on the New Computer</h3>
                </div>
                <p>XAMPP is a free program that lets the computer run the system like a web server. You only need to install it once.</p>
                <ul class="step-list">
                    <li>
                        <span class="step-num">1</span>
                        <div class="step-body">
                            On the <strong>new computer</strong>, open a web browser (Chrome, Edge, or Firefox) and go to:<br>
                            <code>https://www.apachefriends.org</code><br>
                            Click the big <strong>"Download XAMPP for Windows"</strong> button and wait for the file to download.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div class="step-body">
                            Open the downloaded file (it will be named something like <code>xampp-windows-x64-8.x.x-installer.exe</code>). Click <strong>Yes</strong> if Windows asks for permission.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">3</span>
                        <div class="step-body">
                            A setup wizard will open. Just keep clicking <strong>Next</strong> until it starts installing. Leave all options as they are — do not change the install folder. Installation takes a few minutes.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">4</span>
                        <div class="step-body">
                            When it finishes, it will ask if you want to start the <strong>XAMPP Control Panel</strong>. Click <strong>Yes</strong>. You should see a window with a list of services.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">5</span>
                        <div class="step-body">
                            Click the <strong>Start</strong> button next to <strong>Apache</strong>, then click <strong>Start</strong> next to <strong>MySQL</strong>. Both should turn <span style="color:#2c5530;font-weight:700;">green</span> when running. <strong>You must do this every time you turn on the computer.</strong>
                        </div>
                    </li>
                </ul>

                <!-- STEP 3 -->
                <div class="phase-heading">
                    <span class="phase-badge">Step 3</span>
                    <h3>Copy the System Files onto the New Computer</h3>
                </div>
                <ul class="step-list">
                    <li>
                        <span class="step-num">1</span>
                        <div class="step-body">
                            Plug your <strong>flash drive</strong> into the new computer and copy <code>Capstone.zip</code> to the Desktop (or anywhere easy to find).
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div class="step-body">
                            <strong>Right click</strong> on <code>Capstone.zip</code> and choose <strong>"Extract All…"</strong>. In the window that opens, change the destination folder to:<br>
                            <code>C:\xampp\htdocs\</code><br>
                            Then click <strong>Extract</strong>. This places the <code>Capstone</code> folder inside <code>htdocs</code>.
                        </div>
                    </li>
                </ul>
                <div class="callout callout-tip">
                    <i class="fas fa-check-circle"></i>
                    <strong>How to verify:</strong> Open File Explorer and go to <code>C:\xampp\htdocs\</code>. You should see a folder named <strong>Capstone</strong> inside it. If it's there, you're good!
                </div>

                <!-- STEP 4 -->
                <div class="phase-heading">
                    <span class="phase-badge">Step 4</span>
                    <h3>Set Up the Database (Your System's Data Storage)</h3>
                </div>
                <p>The database is where all products, sales, and users are stored. You need to load it into the new computer so the system has something to read from.</p>
                <ul class="step-list">
                    <li>
                        <span class="step-num">1</span>
                        <div class="step-body">
                            Make sure XAMPP is running (Apache and MySQL are green). Then open any browser and go to:<br>
                            <code>http://localhost/phpmyadmin</code><br>
                            A page with a blue/white interface will open — this is phpMyAdmin, the database manager.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div class="step-body">
                            At the top of the page, click the <strong>"Import"</strong> tab.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">3</span>
                        <div class="step-body">
                            Under <strong>"File to Import"</strong>, click <strong>"Choose File"</strong>. A file browser will open. Navigate to:<br>
                            <code>C:\xampp\htdocs\Capstone\Database SQL\</code><br>
                            and select the file <strong>espenida_pos.sql</strong>. Click <strong>Open</strong>.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">4</span>
                        <div class="step-body">
                            Scroll all the way down and click the <strong>"Import"</strong> button. Wait — it may take up to a minute. When it finishes, you will see a green message that says <em>"Import has been successfully finished"</em>.
                        </div>
                    </li>
                </ul>
                <div class="callout callout-tip">
                    <i class="fas fa-check-circle"></i>
                    On the left side of phpMyAdmin you should now see a database called <strong>espenida_pos</strong>. That means it worked!
                </div>

                <!-- STEP 5 -->
                <div class="phase-heading">
                    <span class="phase-badge">Step 5</span>
                    <h3>Install Python (for Product Recommendations)</h3>
                </div>
                <p>Python is a separate program that powers the smart product recommendation feature. If you don't need recommendations, you can skip this step — everything else will still work.</p>
                <ul class="step-list">
                    <li>
                        <span class="step-num">1</span>
                        <div class="step-body">
                            In the browser, go to: <code>https://www.python.org/downloads/</code><br>
                            Click the big yellow <strong>"Download Python"</strong> button.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div class="step-body">
                            Open the downloaded installer. <strong>Before clicking anything else</strong>, look at the very bottom of the installer window for a checkbox that says <strong>"Add Python to PATH"</strong>. <strong>Make sure it is checked (ticked).</strong> This step is very important — without it the recommendations won't work.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">3</span>
                        <div class="step-body">
                            Click <strong>"Install Now"</strong> and wait for it to finish. Click <strong>Close</strong> when done.
                        </div>
                    </li>
                </ul>
                <div class="callout callout-warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Forgot to check "Add Python to PATH"?</strong> Run the installer again, choose <strong>"Modify"</strong>, and on the next screen make sure <strong>"Add Python to environment variables"</strong> is checked.
                </div>

                <!-- STEP 6 -->
                <div class="phase-heading">
                    <span class="phase-badge">Step 6</span>
                    <h3>Open the System in Your Browser</h3>
                </div>
                <p>Everything is set up! Now let's open the system.</p>
                <ul class="step-list">
                    <li>
                        <span class="step-num">1</span>
                        <div class="step-body">
                            Make sure the XAMPP Control Panel is open and both <strong>Apache</strong> and <strong>MySQL</strong> are still running (green). If not, click <strong>Start</strong> for each.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div class="step-body">
                            Open any web browser and type the following in the address bar — exactly as shown — then press <strong>Enter</strong>:
                            <div class="cmd-block">http://localhost/Capstone/Login.php</div>
                            The login page of the system will appear.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">3</span>
                        <div class="step-body">
                            Log in using the <strong>owner or administrator</strong> username and password.
                        </div>
                    </li>
                    <li>
                        <span class="step-num">4</span>
                        <div class="step-body">
                            <strong>(Optional)</strong> To activate the Recommendations feature, click <strong>Recommendations</strong> in the left sidebar. The first time it opens, it will take about 15–30 seconds to start up automatically in the background. You don't need to do anything — just wait.
                        </div>
                    </li>
                </ul>
                <div class="callout callout-tip">
                    <i class="fas fa-bookmark"></i>
                    <strong>Pro tip:</strong> Bookmark <code>http://localhost/Capstone/Login.php</code> in your browser so you can open it quickly next time without typing it out.
                </div>

                <!-- Reminders -->
                <div class="phase-heading">
                    <span class="phase-badge">Reminders</span>
                    <h3>Every Time You Use the System</h3>
                </div>
                <ul class="step-list">
                    <li>
                        <span class="step-num"><i class="fas fa-redo" style="font-size:0.7rem;"></i></span>
                        <div class="step-body">Always <strong>open XAMPP first</strong> and start <strong>Apache</strong> and <strong>MySQL</strong> before opening the browser.</div>
                    </li>
                    <li>
                        <span class="step-num"><i class="fas fa-redo" style="font-size:0.7rem;"></i></span>
                        <div class="step-body">The system only works on the <strong>same computer where XAMPP is installed</strong>. Other devices on the same Wi-Fi can access it if configured, but that requires extra setup.</div>
                    </li>
                </ul>

                <!-- Troubleshooting -->
                <div class="phase-heading">
                    <span class="phase-badge">Help</span>
                    <h3>Something Isn't Working?</h3>
                </div>
                <div class="callout callout-danger">
                    <i class="fas fa-times-circle"></i>
                    <strong>The page says "localhost refused to connect"</strong> — XAMPP is not running. Open XAMPP Control Panel and start Apache and MySQL.
                </div>
                <div class="callout callout-danger">
                    <i class="fas fa-times-circle"></i>
                    <strong>The login page opens but shows a database error</strong> — MySQL is not running, or the database was not imported yet. Go back to Step 4.
                </div>
                <div class="callout callout-danger">
                    <i class="fas fa-times-circle"></i>
                    <strong>"Recommendation Engine Offline"</strong> — Python was not installed correctly or "Add Python to PATH" was not checked. Re-run the Python installer and choose Modify to fix it.
                </div>
                <div class="callout callout-warn">
                    <i class="fas fa-question-circle"></i>
                    <strong>Still stuck?</strong> Contact the system developer or your IT person and show them this guide. The system log files are located at <code>C:\xampp\htdocs\Capstone\ml\logs\ml_server.log</code>.
                </div>
            </div><!-- /.guide-card -->
        </div><!-- /.guide-wrap -->
    </div><!-- /.main-content -->

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
