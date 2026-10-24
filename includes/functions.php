<?php
// includes/functions.php

if (!function_exists('formatPlaytimeTicks')) {
    function formatPlaytimeTicks($ticks) {
        if (!$ticks) return "0m";
        $seconds = floor($ticks / 20);
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) return "{$days}d {$hours}h {$minutes}m";
        if ($hours > 0) return "{$hours}h {$minutes}m";
        return "{$minutes}m";
    }
}

if (!function_exists('formatMcName')) {
    function formatMcName($id) {
        if (empty($id)) return 'Neznámy predmet';

        if (str_contains($id, '/')) {
            $id = str_replace(['/'], ' - ', $id);
        }

        if (str_starts_with(strtolower($id), 'minecraft:')) {
            $name = substr($id, 10);
            return ucwords(str_replace(['_', '-', '.'], ' ', $name));
        }

        if (str_contains($id, ':')) {
            $parts = explode(':', $id, 2);
            $namespace = $parts[0] ?? '';
            $itemName = $parts[1] ?? '';

            if (strlen($namespace) <= 4) {
                $namespace = strtoupper($namespace);
            } else {
                $namespace = ucwords(str_replace(['_', '-', '.'], ' ', $namespace));
            }

            $itemName = ucwords(str_replace(['_', '-', '.'], ' ', $itemName));
            return $namespace . ' - ' . $itemName;
        }

        return ucwords(str_replace(['_', '-', '.'], ' ', $id));
    }
}

if (!function_exists('prettyStatName')) {
    function prettyStatName($name) {
        $name = preg_replace('/^[^:]+:/', '', $name);
        return ucwords(str_replace('_', ' ', $name));
    }
}

if (!function_exists('formatStatVal')) {
    function formatStatVal($cat, $key, $val) {
        if (strpos($key, 'one_cm') !== false) {
            return round($val / 100000, 2) . ' km';
        }
        if (strpos($key, 'time') !== false || strpos($key, 'play_one') !== false) {
            $seconds = floor($val / 20);
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
        }
        return number_format($val);
    }
}
