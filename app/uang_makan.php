<?php

function is_hari_kerja(string $tanggal): bool
{
    return (int)date('N', strtotime($tanggal)) !== 7;
}

function potongan_telat(string $jamMasuk): int
{
    $menit = (strtotime('1970-01-01 ' . $jamMasuk) - strtotime('1970-01-01 08:00:00')) / 60;
    return $menit > 0 ? (int)ceil($menit / 60) * 10000 : 0;
}
