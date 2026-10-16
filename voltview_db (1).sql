-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 16 Okt 2026 pada 15.45
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `voltview_db`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `energy_readings`
--

CREATE TABLE `energy_readings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `voltage` decimal(8,2) DEFAULT NULL,
  `current_a` decimal(10,3) DEFAULT NULL,
  `power_w` decimal(12,2) DEFAULT NULL,
  `energy_kwh` decimal(12,4) DEFAULT NULL,
  `frequency_hz` decimal(6,2) DEFAULT NULL,
  `power_factor` decimal(4,3) DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `measured_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voltage_ln_r` decimal(8,2) DEFAULT NULL,
  `voltage_ln_s` decimal(8,2) DEFAULT NULL,
  `voltage_ln_t` decimal(8,2) DEFAULT NULL,
  `voltage_ll_rs` decimal(8,2) DEFAULT NULL,
  `voltage_ll_st` decimal(8,2) DEFAULT NULL,
  `voltage_ll_tr` decimal(8,2) DEFAULT NULL,
  `current_r` decimal(10,3) DEFAULT NULL,
  `current_s` decimal(10,3) DEFAULT NULL,
  `current_t` decimal(10,3) DEFAULT NULL,
  `current_n` decimal(10,3) DEFAULT NULL,
  `reactive_power_kvar` decimal(12,4) DEFAULT NULL,
  `apparent_power_kva` decimal(12,4) DEFAULT NULL,
  `reactive_energy_kvarh` decimal(12,4) DEFAULT NULL,
  `run_hours` decimal(12,2) DEFAULT NULL,
  `avg_load_percent` decimal(7,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `energy_readings`
--

INSERT INTO `energy_readings` (`id`, `voltage`, `current_a`, `power_w`, `energy_kwh`, `frequency_hz`, `power_factor`, `source`, `measured_at`, `voltage_ln_r`, `voltage_ln_s`, `voltage_ln_t`, `voltage_ll_rs`, `voltage_ll_st`, `voltage_ll_tr`, `current_r`, `current_s`, `current_t`, `current_n`, `reactive_power_kvar`, `apparent_power_kva`, `reactive_energy_kvarh`, `run_hours`, `avg_load_percent`) VALUES
(1, 256.67, 1.200, 750.00, 22.5000, 50.00, 0.900, 'ESP32_TEST', '2026-10-16 11:50:52', 250.00, 291.00, 229.00, 400.00, 399.00, 401.00, 1.200, 1.100, 1.300, 0.200, 0.3500, 0.8300, 10.5000, 120.00, 35.00),
(2, 256.67, 1.200, 750.00, 22.5000, 50.00, 0.900, 'ESP32_TEST', '2026-10-16 12:00:52', 250.00, 291.00, 229.00, 400.00, 399.00, 401.00, 1.200, 1.100, 1.300, 0.200, 0.3500, 0.8300, 10.5000, 120.00, 35.00),
(3, 266.67, 1.200, 750.00, 22.5000, 50.00, 0.900, 'ESP32_TEST', '2026-10-16 12:11:01', 250.00, 291.00, 259.00, 400.00, 109.00, 201.00, 1.200, 1.100, 1.300, 0.200, 0.3500, 0.8300, 10.5000, 120.00, 35.00),
(4, 266.67, 1.200, 750.00, 22.5000, 50.00, 0.900, 'ESP32_TEST', '2026-10-16 12:21:01', 250.00, 291.00, 259.00, 400.00, 109.00, 201.00, 1.200, 1.100, 1.300, 0.200, 0.3500, 0.8300, 10.5000, 120.00, 35.00);

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `energy_readings`
--
ALTER TABLE `energy_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_measured_at` (`measured_at`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `energy_readings`
--
ALTER TABLE `energy_readings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
