<?php
require_once __DIR__ . '/config/db.php';

try {
    $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo "<h2>Database connection successful</h2>";

    echo "<p>Connected database: "
        . htmlspecialchars($databaseName, ENT_QUOTES, 'UTF-8')
        . "</p>";

    echo "<p>Number of tables: " . count($tables) . "</p>";

    if (count($tables) === 0) {
        echo "<p>The database is connected, but no tables exist yet.</p>";
    } else {
        echo "<h3>Available tables</h3><ul>";

        foreach ($tables as $table) {
            echo "<li>"
                . htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
                . "</li>";
        }

        echo "</ul>";
    }
} catch (PDOException $e) {
    echo "<p>The connection opened, but the database test failed.</p>";
}