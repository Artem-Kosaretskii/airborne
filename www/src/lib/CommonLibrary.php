<?php
declare(strict_types=1);

namespace Airborne;

/**
 *
 */
class CommonLibrary
{
    /**
     * Приведение телефонных номеров России и Беларуси к единому формату +7... и +375...
     * Для занесения в БД для поиска дублей используются только числовые значения ($compare=false), для сравнения при объединении - любые значения ($compare=true)
     * @param string $phone
     * @param bool $compare
     * @return string
     */
    public static function trimPhone(string $phone, bool $compare = false): string
    {
        $unprocessed = $phone;
        $phone = preg_replace('/[^\d]/', '', $phone);
        $phone = preg_replace('/\A(7|8|)([3489]\d{9})\z/', '7$2', $phone);
        //$phone = preg_replace('/\A(375)(\d{9})\z/', '+375$2', $phone);
        //if (
        //    $compare && !preg_match('/\A\7[3489]\d{9}\z/', $phone)
        //&& !preg_match('/\A\+375\d{9}\z/', $phone)
        //) { $phone = $unprocessed; }
        return $phone;
    }
}