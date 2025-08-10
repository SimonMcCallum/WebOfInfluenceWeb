<?php
// --- DATABASE CREDENTIALS ---
$servername = "localhost";
$username = "ludog319_kng"; // Replace with your database username
$password = "WFoSE!"; // Replace with your database password
$dbname = "ludog319_webofinfluence"; // Replace with your database name

// --- ESTABLISH CONNECTION ---
// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection for errors
if ($conn->connect_error) {
  // If connection fails, stop the script and show the error
  die("Connection failed: " . $conn->connect_error);
}

// --- CONNECTION SUCCESSFUL ---
// If the script reaches this point, the connection was successful.
// You can remove or comment out this line in your final application.
echo "Connected successfully to the database!";

// List all tables in the database
echo "<h2>Tables in database '$dbname':</h2>";
$sql = "SHOW TABLES";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<ul>";
    while($row = $result->fetch_array()) {
        $tableName = $row[0];
        echo "<li>$tableName</li>";
        
        // Get row count for each table
        $countSql = "SELECT COUNT(*) as count FROM `$tableName`";
        $countResult = $conn->query($countSql);
        if ($countResult) {
            $countRow = $countResult->fetch_assoc();
            echo " (" . $countRow['count'] . " rows)";
        }
    }
    echo "</ul>";
} else {
    echo "No tables found in database.";
}

// Test the information_schema query that the admin uses
echo "<h2>Testing information_schema query:</h2>";
$sql = "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = '$dbname' ORDER BY TABLE_NAME";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<ul>";
    while($row = $result->fetch_assoc()) {
        echo "<li>" . $row['TABLE_NAME'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "No tables found via information_schema.";
}

$conn->close();

?>
