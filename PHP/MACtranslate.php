<?php
session_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <link rel="stylesheet" href="WEH.css" media="screen">
    <meta charset="UTF-8">
    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 20px;
        }

        .mactranslate-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #1e1e1e;
            padding: 30px;
            border-radius: 12px;
            border: 2px solid #11a50d;
        }

        .mactranslate-title {
            text-align: center;
            margin-bottom: 25px;
            color: #11a50d;
        }

        .mactranslate-form-group {
            margin-bottom: 20px;
        }

        .mactranslate-label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        .mactranslate-input {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            border: 1px solid #444;
            border-radius: 6px;
            background-color: #2a2a2a;
            color: #ffffff;
        }

        .mactranslate-input:focus {
            outline: none;
            border-color: #11a50d;
        }

        .mactranslate-button {
            background-color: #11a50d;
            color: white;
            border: none;
            padding: 12px 20px;
            font-size: 16px;
            border-radius: 6px;
            cursor: pointer;
            display: block;
            margin: 0 auto;
        }

        .mactranslate-button:hover {
            background-color: #0e8b0a;
        }

        .mactranslate-warning {
            display: inline-block;
            color: #ff5555;
            font-size: 18px;
            margin-left: 8px;
            cursor: help;
        }

        .mactranslate-tooltip {
            visibility: hidden;
            background-color: #333;
            color: #fff;
            text-align: left;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            font-size: 13px;
            width: 220px;
            bottom: 120%;
            left: 50%;
            margin-left: -110px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .mactranslate-warning:hover .mactranslate-tooltip {
            visibility: visible;
            opacity: 1;
        }

        .mactranslate-form-group {
            position: relative;
        }

    </style>
</head>
<body>

<?php
require('template.php');
mysqli_set_charset($conn, "utf8");

if (auth($conn) && (isset($_SESSION["Webmaster"]) && $_SESSION["Webmaster"] === true)) {
    load_menu();

    $mac_dots = $_POST['mac_dots'] ?? '';
    $mac_colons = $_POST['mac_colons'] ?? '';
    $mac_minus = $_POST['mac_minus'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (!empty($mac_dots)) {
            $mac_clean = strtolower(str_replace('.', '', $mac_dots));
            $mac_colons = strtolower(rtrim(chunk_split($mac_clean, 2, ':'), ':'));
            $mac_minus = strtolower(rtrim(chunk_split($mac_clean, 2, '-'), '-'));
        } elseif (!empty($mac_colons)) {
            $mac_clean = strtolower(str_replace(':', '', $mac_colons));
            $mac_dots = strtolower(rtrim(chunk_split($mac_clean, 4, '.'), '.'));
            $mac_minus = strtolower(rtrim(chunk_split($mac_clean, 2, '-'), '-'));
        } elseif (!empty($mac_minus)) {
            $mac_clean = strtolower(str_replace('-', '', $mac_minus));
            $mac_colons = strtolower(rtrim(chunk_split($mac_clean, 2, ':'), ':'));
            $mac_dots = strtolower(rtrim(chunk_split($mac_clean, 4, '.'), '.'));
        }
    }
    ?>


    <div class="mactranslate-container">
        <h2 class="mactranslate-title">MAC-Adressen-Konverter</h2>
        <form method="post" action="">
            <div class="mactranslate-form-group">
                <label for="mac_dots" class="mactranslate-label">Cisco-Format (z.B. 2c54.2dd2.d83f)
                    <span id="warn_dots" class="mactranslate-warning" style="display:none;">
                        ⚠️
                        <span class="mactranslate-tooltip">Gültiges Format: 3 Gruppen mit 4 Hex-Zeichen, getrennt durch Punkte.</span>
                    </span>
                </label>
                <input type="text" id="mac_dots" name="mac_dots" class="mactranslate-input" value="<?php echo htmlspecialchars($mac_dots); ?>">
            </div>
            <div class="mactranslate-form-group">
                <label for="mac_colons" class="mactranslate-label">Standardformat (z.B. 2c:54:2d:d2:d8:3f)
                    <span id="warn_colons" class="mactranslate-warning" style="display:none;">
                        ⚠️
                        <span class="mactranslate-tooltip">Gültiges Format: 6 Gruppen mit 2 Hex-Zeichen, getrennt durch Doppelpunkte.</span>
                    </span>
                </label>
                <input type="text" id="mac_colons" name="mac_colons" class="mactranslate-input" value="<?php echo htmlspecialchars($mac_colons); ?>">
            </div>
            <div class="mactranslate-form-group">
                <label for="mac_minus" class="mactranslate-label">Mit Minus (z.B. 2c-54-2d-d2-d8-3f)
                    <span id="warn_minus" class="mactranslate-warning" style="display:none;">
                        ⚠️
                        <span class="mactranslate-tooltip">Gültiges Format: 6 Gruppen mit 2 Hex-Zeichen, getrennt durch Bindestriche.</span>
                    </span>
                </label>
                <input type="text" id="mac_minus" name="mac_minus" class="mactranslate-input" value="<?php echo htmlspecialchars($mac_minus); ?>">
            </div>
        </form>
    </div>

    <script>
        const regexes = {
            mac_dots: /^[a-f0-9]{4}\.[a-f0-9]{4}\.[a-f0-9]{4}$/i,
            mac_colons: /^([a-f0-9]{2}:){5}[a-f0-9]{2}$/i,
            mac_minus: /^([a-f0-9]{2}-){5}[a-f0-9]{2}$/i
        };

        function formatMAC(mac) {
            return mac.toLowerCase().replace(/[^a-f0-9]/gi, '');
        }

        function validate(id) {
            const input = document.getElementById(id);
            const warn = document.getElementById("warn_" + id.split('_')[1]);
            const regex = regexes[id];
            const val = input.value.trim();
            warn.style.display = (val && !regex.test(val)) ? "inline-block" : "none";
        }

        function fillFields(from, value) {
            const clean = formatMAC(value);
            if (clean.length !== 12) return;

            if (from !== 'mac_dots') {
                document.getElementById('mac_dots').value = clean.match(/.{1,4}/g).join('.');
                validate('mac_dots');
            }
            if (from !== 'mac_colons') {
                document.getElementById('mac_colons').value = clean.match(/.{1,2}/g).join(':');
                validate('mac_colons');
            }
            if (from !== 'mac_minus') {
                document.getElementById('mac_minus').value = clean.match(/.{1,2}/g).join('-');
                validate('mac_minus');
            }
        }

        ['mac_dots', 'mac_colons', 'mac_minus'].forEach(id => {
            const input = document.getElementById(id);
            input.addEventListener('input', function () {
                fillFields(id, this.value);
                validate(id);
            });
            validate(id);
        });
    </script>

<?php
} else {
    header("Location: denied.php");
}
$conn->close();
?>

</body>
</html>
