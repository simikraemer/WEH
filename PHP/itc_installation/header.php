<?php

$totalBase = '/itc_installation/';

$navigation = [
    'Geräte' => [
        'icon' => '💻',
        'base' => 'devices/',
        'items' => [
            ['label' => '📋 Übersicht', 'href' => 'Devices.php', 'deactive' => true],
            ['label' => '➕ Neues Gerät', 'href' => 'New.php', 'deactive' => true],
            ['label' => '📚 Masseneintrag', 'href' => 'Masseneintrag.php', 'deactive' => true],
            ['label' => '🗑️ Deinventarisiert', 'href' => 'Deinvent.php', 'deactive' => true],
            ['label' => '🎁 Verkaufsschrank', 'href' => 'Verkauf.php', 'deactive' => true],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'User' => [
        'icon' => '👤',
        'base' => 'users/',
        'items' => [
            ['label' => '📋 Übersicht', 'href' => 'Users.php', 'deactive' => true],
            ['label' => '➕ Neuer User', 'href' => 'New.php', 'deactive' => true],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'Installation' => [
        'icon' => '🚀',
        'base' => 'installation/',
        'items' => [
            ['label' => '✅ Fortschritt', 'href' => 'Installation.php'],
            ['label' => '➕ Neue Installation', 'href' => 'New.php'],
            ['label' => '📋 Übersicht', 'href' => 'Archiv.php'],
            ['label' => '📊 Statistik', 'href' => 'Statistik.php'],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'Bestellung' => [
        'icon' => '🛒',
        'base' => 'bestellungen/',
        'items' => [
            ['label' => '📑 Übersicht', 'href' => 'Bestellungen.php', 'deactive' => true],
            ['label' => '➕ Neue Bestellung', 'href' => 'New.php', 'deactive' => true],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'Quittung' => [
        'icon' => '🧾',
        'base' => 'quittungen/',
        'items' => [
            ['label' => '📋 Übersicht', 'href' => 'Quittungen.php', 'deactive' => true],
            ['label' => '➕ Neue Quittung', 'href' => 'New.php', 'deactive' => true],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'Software' => [
        'icon' => '🧩',
        'base' => 'software/',
        'items' => [
            ['label' => '📋 Übersicht', 'href' => 'Software.php', 'deactive' => true],
            ['label' => '➕ Neue Software', 'href' => 'New.php', 'deactive' => true],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'Tools' => [
        'icon' => '🧰',
        'base' => 'tools/',
        'items' => [
            ['label' => '📋 Übersicht', 'href' => 'Tools.php', 'deactive' => true],
            ['label' => '➕ Neues Werkzeug', 'href' => 'New.php', 'deactive' => true],
            ['label' => '✏️ Bearbeiten', 'href' => 'Edit.php', 'deactive' => true],
        ]
    ],
    'Einstellungen' => [
        'icon' => '⚙️',
        'base' => 'admin/',
        'items' => [
            ['label' => '🗿 Konstanten', 'href' => 'Constants.php'],
            ['label' => '💸 Finanzen', 'href' => 'Finanzen.php', 'deactive' => true],
            ['label' => '🔐 Session', 'href' => 'Session.php', 'deactive' => true],
        ]
    ],
];

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<div class="main-header">
    <div class="logo-title">IT-Administration</div>
    <nav class="main-nav">
        <?php foreach ($navigation as $group => $data): 
            $base = $data['base'] ?? '';
            $isActiveGroup = false;

            $fullCurrentPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

            foreach ($data['items'] as $item) {
                if ($item['href'] === '#') continue;

                $fullItemPath = trim($totalBase . $base . $item['href'], '/');
                if ($fullCurrentPath === $fullItemPath) {
                    $isActiveGroup = true;
                    break;
                }
            }

        ?>
        <div class="nav-group <?= $isActiveGroup ? 'active' : '' ?>">
            <?php
            $firstHref = '#';
            foreach ($data['items'] as $firstItem) {
                if (!($firstItem['deactive'] ?? false) && $firstItem['href'] !== '#') {
                    $firstHref = $totalBase . trim($base . $firstItem['href'], '/');
                    break;
                }
            }
            ?>
            <a href="<?= $firstHref ?>" class="nav-link"><?= $data['icon'] . ' ' . $group ?></a>
            <div class="dropdown">
                <?php foreach ($data['items'] as $item): 
                    $fullHref = ($item['href'] === '#') ? '#' : $totalBase . trim($base . $item['href'], '/');
                    $isActive = basename($item['href']) === $currentPage;
                    $isDeactive = $item['deactive'] ?? false;
                    $classes = 'nav-sublink';
                    if ($isActive) $classes .= ' active';
                    elseif ($isDeactive) $classes .= ' deactive';
                ?>
                    <a href="<?= $fullHref ?>" class="<?= $classes ?>"><?= $item['label'] ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </nav>
</div>
