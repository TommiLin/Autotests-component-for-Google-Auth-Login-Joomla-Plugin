<?php

/**
 * @package     Google Auth Tests
 * @license     GNU General Public License version 2 or later
 */

namespace TommiLin\Component\GoogleAuthTests\Administrator\Testing;

\defined('_JEXEC') or die;

use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\AccountSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\BrowserSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\ConsentSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\HttpSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\InstallationSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\LanguageSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\LoggingSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\RedirectSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\RenderSuite;
use TommiLin\Component\GoogleAuthTests\Administrator\Testing\Suite\StateSuite;

final class TestRegistry
{
    public const TOOL_VERSION = '1.0.1';

    /** @return array<int, class-string> */
    private static function suites(): array
    {
        return [
            InstallationSuite::class,
            LanguageSuite::class,
            RenderSuite::class,
            StateSuite::class,
            RedirectSuite::class,
            ConsentSuite::class,
            AccountSuite::class,
            LoggingSuite::class,
            HttpSuite::class,
            BrowserSuite::class,
        ];
    }

    /** @return array<int, array<string,mixed>> flat list of test definitions */
    public static function definitions(): array
    {
        $all = [];

        foreach (self::suites() as $class) {
            foreach ($class::tests() as $test) {
                $all[] = $test + [
                    'group'      => $class::GROUP_ID,
                    'groupTitle' => $class::GROUP_TITLE,
                    'class'      => $class,
                    'mode'       => 'server',
                    'write'      => false,
                    'risky'      => false,
                    'desc'       => '',
                ];
            }
        }

        return $all;
    }

    public static function find(string $id): ?array
    {
        foreach (self::definitions() as $def) {
            if ($def['id'] === $id) {
                return $def;
            }
        }

        return null;
    }

    /** Definitions grouped for the UI (no class names leaked). */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::definitions() as $def) {
            $gid = $def['group'];
            $groups[$gid] ??= ['id' => $gid, 'title' => $def['groupTitle'], 'tests' => []];
            $groups[$gid]['tests'][] = [
                'id'    => $def['id'],
                'title' => $def['title'],
                'desc'  => $def['desc'],
                'mode'  => $def['mode'],
                'write' => (bool) $def['write'],
                'risky' => (bool) $def['risky'],
            ];
        }

        return array_values($groups);
    }
}
