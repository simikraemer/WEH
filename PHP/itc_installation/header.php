<!-- header.php -->
<div class="main-header">
    <div class="logo-title">💻 IT-Administration Neugeräte</div>
    <nav class="main-nav">
        <a href="Quittung.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'Quittung.php' ? 'active' : '' ?>">✏️ Quittung</a>
        <a class="nav-link deactive">🔨 Bearbeiten</a>
        <a href="Installation.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'Installation.php' ? 'active' : '' ?>">📋 Übersicht</a>
        <a href="New.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'New.php' ? 'active' : '' ?>">➕ Neuer Eintrag</a>
        <a href="Archiv.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'Archiv.php' ? 'active' : '' ?>">📁 Archiv</a>
        <a href="Statistik.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'Statistik.php' ? 'active' : '' ?>">📊 Statistik</a>
        <a href="Admin.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'Admin.php' ? 'active' : '' ?>">⚙️ Einstellungen</a>
    </nav>
</div>
