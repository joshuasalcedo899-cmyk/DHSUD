<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Mail Tracking Dashboard</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: #e9e9e9;
    margin: 0;
}

/* HEADER */
.header {
    background: white;
    padding: 10px 20px;
    border-bottom: 3px solid #1f3c88;
    display: flex;
    align-items: center;
    gap: 10px;
}

.header img {
    height: 40px;
}

.header h3 {
    font-size: 14px;
    color: #1f3c88;
}

/* CONTAINER */
.container {
    padding: 20px;
}

h2 {
    margin-bottom: 10px;
}

/* STAT CARDS */
.stats {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
}

.stat-box {
    padding: 8px 20px;
    color: white;
    font-size: 12px;
    border-radius: 4px;
    min-width: 120px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.red { background: #b64545; }
.yellow { background: #c2c200; }
.green { background: #2d8f2d; }
.black { background: black; }
.purple { background: #8a3d8f; }

.stat-box span {
    background: white;
    color: black;
    padding: 2px 6px;
    border-radius: 3px;
}

/* TABLE */
table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

th {
    background: #1f3c88;
    color: white;
    padding: 8px;
    font-size: 12px;
}

td {
    padding: 6px;
    border: 1px solid #ccc;
    font-size: 12px;
}

button {
    background: #1f3c88;
    color: white;
    border: none;
    padding: 4px 10px;
    border-radius: 3px;
    cursor: pointer;
}

.add-btn {
    margin-top: 10px;
    padding: 8px 15px;
}

/* SEARCH */
.search-box {
    float: right;
    margin-bottom: 10px;
}
</style>

</head>
<body>

<!-- HEADER -->
<div class="header">
    <img src="dhsud.png"> <!-- Replace with your logo -->
    <h3>REPUBLIC OF THE PHILIPPINES<br>
    DEPARTMENT OF HUMAN SETTLEMENTS AND URBAN DEVELOPMENT</h3>
</div>

<div class="container">

<h2>Welcome, Admin!</h2>

<!-- STATISTICS -->
<div class="stats">
    <div class="stat-box red">Returned to Sender <span>0</span></div>
    <div class="stat-box yellow">Ongoing Delivery <span>0</span></div>
    <div class="stat-box green">Delivered <span>0</span></div>
    <div class="stat-box black">Total <span>0</span></div>
    <div class="stat-box purple">Non-delivery Rate <span>0%</span></div>
</div>

<h3>MAIL TRACKING RECORDS</h3>

<div class="search-box">
    <select>
        <option>Year</option>
        <option>2026</option>
        <option>2025</option>
    </select>
    <input type="text" placeholder="Search...">
</div>

<table>
<thead>
<tr>
    <th>Notice No.</th>
    <th>Date to AFD</th>
    <th>Parcel No.</th>
    <th>Recipient Details</th>
    <th>Parcel Details</th>
    <th>Sender Details</th>
    <th>File Name (PDF)</th>
    <th>Tracking No.</th>
    <th>Status</th>
    <th>Transmittal Received By</th>
    <th>Date</th>
    <th>Evaluator</th>
    <th>Action</th>
</tr>
</thead>

<tbody>
<?php
// SAMPLE EMPTY ROWS
for($i=0;$i<5;$i++){
    echo "<tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td>
            <select>
                <option>Select Status</option>
                <option>Delivered</option>
                <option>Ongoing Delivery</option>
                <option>Returned</option>
            </select>
        </td>
        <td></td>
        <td></td>
        <td></td>
        <td><button>Track</button></td>
    </tr>";
}
?>
</tbody>
</table>

<button class="add-btn">Add</button>

</div>

</body>
</html>
