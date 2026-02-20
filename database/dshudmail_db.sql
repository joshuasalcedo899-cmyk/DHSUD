-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2026 at 02:14 AM
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
-- Database: `dshudmail_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `archive`
--

CREATE TABLE `archive` (
  `id` int(11) NOT NULL,
  `original_mail_id` int(11) NOT NULL,
  `Notice/Order Code` varchar(100) NOT NULL,
  `Date released to AFD` date NOT NULL,
  `Parcel No.` int(20) NOT NULL,
  `Recipient Details` varchar(100) NOT NULL,
  `Parcel Details` varchar(100) NOT NULL,
  `Sender Details` varchar(250) NOT NULL,
  `File Name (PDF)` varchar(100) NOT NULL,
  `Tracking No.` varchar(50) NOT NULL,
  `Status` varchar(100) NOT NULL,
  `Transmittal Remarks/Received By` varchar(100) NOT NULL,
  `Date` date NOT NULL,
  `Evaluator` varchar(100) NOT NULL,
  `deleted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `archive`
--

INSERT INTO `archive` (`id`, `original_mail_id`, `Notice/Order Code`, `Date released to AFD`, `Parcel No.`, `Recipient Details`, `Parcel Details`, `Sender Details`, `File Name (PDF)`, `Tracking No.`, `Status`, `Transmittal Remarks/Received By`, `Date`, `Evaluator`, `deleted_at`) VALUES
(4, 18, '1', '2026-02-19', 1, '', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)', 'EMES-260219-001', '', '', '', '0000-00-00', '', '2026-02-19 15:45:39'),
(5, 20, '142322', '2026-02-19', 0, '', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)', 'EMES-260219-000', '6088904982508061', 'DELIVERED', 'Noel Rielago (Security Guard)', '2026-01-30', '', '2026-02-19 16:06:20'),
(6, 21, '4345', '2026-02-19', 0, '', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)', 'EMES-260219-000', '6088904982508061', 'DELIVERED', 'Noel Rielago (Security Guard)', '2026-01-30', '', '2026-02-19 16:11:40');

-- --------------------------------------------------------

--
-- Table structure for table `mailtracking`
--

CREATE TABLE `mailtracking` (
  `id` int(11) NOT NULL,
  `Notice/Order Code` varchar(100) NOT NULL,
  `Date released to AFD` date NOT NULL,
  `Parcel No.` int(20) NOT NULL,
  `Recipient Details` varchar(100) NOT NULL,
  `Parcel Details` varchar(100) NOT NULL,
  `Sender Details` varchar(250) NOT NULL,
  `File Name (PDF)` varchar(100) NOT NULL,
  `Tracking No.` varchar(50) NOT NULL,
  `Status` varchar(100) NOT NULL,
  `Transmittal Remarks/Received By` varchar(100) NOT NULL,
  `Date` date NOT NULL,
  `Evaluator` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mailtracking`
--

INSERT INTO `mailtracking` (`id`, `Notice/Order Code`, `Date released to AFD`, `Parcel No.`, `Recipient Details`, `Parcel Details`, `Sender Details`, `File Name (PDF)`, `Tracking No.`, `Status`, `Transmittal Remarks/Received By`, `Date`, `Evaluator`) VALUES
(1, 'OIAS-2026-0013', '2026-02-05', 41, 'MR. ALBERT V. QUINTOS\r\nHead-External Liaison\r\nPROPERTY COMPANY OF FRIENDS, INC.\r\n55 Tinio St.,Brgy.', 'GLENBROOK PHASE 3\r\nORDER OF IMPOSITION OF ADMINISTRATIVE SANCTION\r\n(January 29, 2026)', 'Department of Human Settlements and Urban Development Region 4A\r\nHREDRD-EMES\r\n0935 542 1538\r\n\r\n(February-05-2026)', 'proof_2230226497718863.pdf', '2230226497718863', 'RETURNED TO SENDER', '', '2026-01-16', ''),
(2, 'RO4A-2025-1223-31736', '2026-02-03', 45, 'TEST', 'LIMA TECHNOLOGY CENTER SPECIAL ECONOMIC ZONE BLK.7 PH.2\r\n(January 21, 2026)\r\nACK LETTER', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-03-2026)\nBatch ID: BATCH-20260218-014011-E599', 'proof_6088904982508061.pdf', '6088904982508061', 'DELIVERED', 'Miventte Acosta (Bldg. Staff)', '2026-02-09', ''),
(3, 'RO4A-2025-1223-31741', '2026-02-03', 45, 'TEST', 'LIMA COMMERCIAL LOTS\r\n(January 21, 2026)\r\nACK LETTER', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-03-2026)\nBatch ID: BATCH-20260218-014011-E599', 'proof_6088904982508061.pdf', '6088904982508061', 'DELIVERED', 'Miventte Acosta (Bldg. Staff)', '2026-02-09', ''),
(4, 'RO4A-2025-1223-31742', '2026-02-03', 45, 'TEST', 'LIMA TECHNOLOGY CENTER BLOCK 9 EXPANSION\r\n(January 21, 2026)\r\nACK LETTER', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-03-2026)\nBatch ID: BATCH-20260218-014011-E599', 'proof_6088904982508061.pdf', '6088904982508061', 'DELIVERED', 'Miventte Acosta (Bldg. Staff)', '2026-02-09', ''),
(5, 'RO4A-2025-1223-31743', '2026-02-03', 45, 'TEST', 'LIMA TECHNOLOGY CENTER BLOCK 8 PHASE 3 EXPANSION\r\n(January 21, 2026)\r\nACK LETTER', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-03-2026)\nBatch ID: BATCH-20260218-014011-E599', 'proof_6088904982508061.pdf', '6088904982508061', 'DELIVERED', 'Miventte Acosta (Bldg. Staff)', '2026-02-09', ''),
(6, 'RO4A-ORD-2025-1208-008 V-2025-0902 CC-2025-068', '2026-01-07', 45, 'MS. LIBERTY G. SOTERO\r\nTechnical Services\r\nBRIA HOMES, INC.\r\nGROUND FLR., ASPEN BLDG., SOLASTA, \r\nCA', '08 DECEMBER 2025\r\nOMNIBUS NOTICE\r\nRE: MA DOLORES ANN M. MASAOY -\r\nBRIA HOMES BARAS', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(January-07-2026)', 'proof_8326432791250088.pdf', '8326432791250088', 'RETURNED TO SENDER', '', '2026-01-27', ''),
(7, '123', '2026-02-18', 12, '', 'Test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-18-2026)', 'proof_6577483153758338.pdf', '6577483153758338', 'DELIVERED', 'Giecel Merano (Co-worker)', '2026-01-30', ''),
(8, 'RO4A-ORD-2025-1208-013 V-2025-0907', '2026-02-03', 7, 'NABAJA LAND CORPORATION\r\n3rd Level A-Sun Centre, 25 M. Santos Ext.\r\nCorner L. Sumulong Memorial Circ', 'LA ROSA HOMES TANAY\r\nNAV\r\n(December 19, 2025)', 'Department of Human Settlements and Urban Development Region 4A\r\nHREDRD-EMES\r\n0935 542 1538\r\n\r\n(February-03-2026)\nBatch ID: BATCH-20260218-034517-6D65', 'proof_5878394576333938.pdf', '5878394576333938', 'DELIVERED', 'Grace Espiritu (Bldg. Staff)', '2026-02-06', ''),
(9, 'RO4A-ORD-2025-1208-015 V-2025-0908', '2026-02-03', 7, 'NABAJA LAND CORPORATION\r\n3rd Level A-Sun Centre, 25 M. Santos Ext.\r\nCorner L. Sumulong Memorial Circ', 'LA ROSA HOMES BARAS\r\nNAV\r\n(December 19, 2025)', 'Department of Human Settlements and Urban Development Region 4A\r\nHREDRD-EMES\r\n0935 542 1538\r\n\r\n(February-03-2026)\nBatch ID: BATCH-20260218-034517-6D65', 'proof_5878394576333938.pdf', '5878394576333938', 'DELIVERED', 'Grace Espiritu (Bldg. Staff)', '2026-02-06', ''),
(10, '345', '2026-02-18', 12, 'TEST', 'adswq', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-18-2026)', 'EMES-260218-012', '6577483153758338', 'DELIVERED', 'Giecel Merano (Co-worker)', '2026-01-30', ''),
(11, '67', '2026-02-18', 1, 'Meron naka dark yung status nya', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-18-2026)', 'proof_9030847756792949.pdf', '9030847756792949', 'RETURNED TO SENDER', '', '2026-01-10', ''),
(12, '68', '2026-02-18', 1, 'test', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-18-2026)', 'proof_64350-862.pdf', '64350-862', 'RETURNED TO SENDER', '', '2025-12-22', ''),
(13, 'RO4A-ORD-2026-0121-1480 V-2026-0157', '2026-02-05', 2, 'Ma.Cristina Diza\r\nB13 L48 Purok Tagumoay Bagong Nayon Antipolo City Rizal', 'JAMSHED FARMS\r\nNAV:\r\n(January 28, 2026)', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-05-2026)', '', '3541565517011382', 'ONGOING DELIVERY', '', '2026-02-06', ''),
(14, '3456', '2026-02-13', 4, 'wertyu', 'qwertyukl', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-13-2026)', 'EMES-260213-004', '6088904982508061', 'DELIVERED', 'Noel Rielago (Security Guard)', '2026-01-30', ''),
(15, 'RO4A-2025-1223-31735', '2026-02-03', 45, 'TEST', 'LIMA TECHNOLOGY CENTER SPECIAL ECONOMIC ZONE PHASE 2B\r\n(January 21, 2026)\r\nACK LETTER', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-03-2026)\nBatch ID: BATCH-20260218-014011-E599', 'proof_6088904982508061.pdf', '6088904982508061', 'DELIVERED', 'Miventte Acosta (Bldg. Staff)', '2026-02-09', ''),
(16, '1', '2026-02-19', 0, '', 'test1', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)\nBatch ID: BATCH-20260219-023906-7408', 'EMES-260219-000', '8710679919185027', 'DELIVERED', 'Lg Gonzaga  (Bldg. Staff)', '2026-01-30', ''),
(17, '2', '2026-02-19', 0, '', 'test2', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)\nBatch ID: BATCH-20260219-023906-7408', 'EMES-260219-000', '8710679919185027', 'DELIVERED', 'Lg Gonzaga  (Bldg. Staff)', '2026-01-30', ''),
(19, '114', '2026-02-19', 1, '', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)', 'EMES-260219-001', '8710679919185027', 'DELIVERED', 'Lg Gonzaga  (Bldg. Staff)', '2026-01-30', ''),
(22, '23', '2026-02-19', 1, '', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)', 'EMES-260219-001', '6088904982508061', 'DELIVERED', 'Noel Rielago (Security Guard)', '2026-01-30', ''),
(23, '56', '2026-02-19', 0, '', 'test', 'Department of Human Settlements and Urban Development Region 4A\nHREDRD-EMES\n0935 542 1538\n\n(February-19-2026)', 'EMES-260219-000', '6088904982508061', 'DELIVERED', 'Noel Rielago (Security Guard)', '2026-01-30', '');

--
-- Triggers `mailtracking`
--
DELIMITER $$
CREATE TRIGGER `trg_mailtracking_before_delete` BEFORE DELETE ON `mailtracking` FOR EACH ROW BEGIN
  INSERT INTO `archive` (
    `original_mail_id`,
    `Notice/Order Code`,
    `Date released to AFD`,
    `Parcel No.`,
    `Recipient Details`,
    `Parcel Details`,
    `Sender Details`,
    `File Name (PDF)`,
    `Tracking No.`,
    `Status`,
    `Transmittal Remarks/Received By`,
    `Date`,
    `Evaluator`,
    `deleted_at`
  ) VALUES (
    OLD.`id`,
    OLD.`Notice/Order Code`,
    OLD.`Date released to AFD`,
    OLD.`Parcel No.`,
    OLD.`Recipient Details`,
    OLD.`Parcel Details`,
    OLD.`Sender Details`,
    OLD.`File Name (PDF)`,
    OLD.`Tracking No.`,
    OLD.`Status`,
    OLD.`Transmittal Remarks/Received By`,
    OLD.`Date`,
    OLD.`Evaluator`,
    NOW()
  );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@dhsud.com', '$2y$10$aBJpl0gNN4xrsYQn9nQm8OWOQnnPprmZAI9FdT5nTfHsLJCDDn7em', '2026-02-14 11:31:18', '2026-02-14 11:31:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `archive`
--
ALTER TABLE `archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_archive_original_mail_id` (`original_mail_id`);

--
-- Indexes for table `mailtracking`
--
ALTER TABLE `mailtracking`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `archive`
--
ALTER TABLE `archive`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `mailtracking`
--
ALTER TABLE `mailtracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
