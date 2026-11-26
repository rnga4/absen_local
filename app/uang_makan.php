<?php

function is_hari_kerja(string $tanggal): bool
{
    return (int)date('N', strtotime($tanggal)) !== 7;
}

function hari_kerja_bulan(string $start, string $end): int
{
    $n = 0;
    for ($t = strtotime($start); $t <= strtotime($end); $t += 86400) {
        if (is_hari_kerja(date('Y-m-d', $t))) $n++;
    }
    return $n;
}

function potongan_telat(string $jamMasuk): int
{
    $menit = (strtotime('1970-01-01 ' . $jamMasuk) - strtotime('1970-01-01 08:00:00')) / 60;
    return $menit > 0 ? (int)ceil($menit / 60) * 10000 : 0;
}
