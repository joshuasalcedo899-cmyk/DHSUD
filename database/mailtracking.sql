-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 03, 2026 at 03:28 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mailtrackdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `mailtracking`
--

CREATE TABLE `mailtracking` (
  `Notice/Order Code` text NOT NULL,
  `Date released to AFD` date NOT NULL,
  `Parcel No.` int(11) NOT NULL,
  `Recipient Details` text NOT NULL,
  `Parcel Details` text NOT NULL,
  `Sender Details` text NOT NULL,
  `File Name (PDF)` text NOT NULL,
  `Tracking No.` varchar(255) NOT NULL,
  `Status` text NOT NULL,
  `Transmittal Remarks/Received By` text NOT NULL,
  `Date` date NOT NULL,
  `Evaluator` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mailtracking`
--

INSERT INTO `mailtracking` (`Notice/Order Code`, `Date released to AFD`, `Parcel No.`, `Recipient Details`, `Parcel Details`, `Sender Details`, `File Name (PDF)`, `Tracking No.`, `Status`, `Transmittal Remarks/Received By`, `Date`, `Evaluator`) VALUES
('', '0000-00-00', 0, '', '', '', '', 2000, '', '0', '0000-00-00', ''),
('asd', '0000-00-00', 0, '', '', '', '', 123, '', '0', '0000-00-00', ''),
('V123', '0000-00-00', 0, '', '', '', '', 200, '', '0', '0000-00-00', '');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `mailtracking`
--
ALTER TABLE `mailtracking`
  ADD PRIMARY KEY (`Notice/Order Code`(100));
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
