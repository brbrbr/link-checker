<?php

namespace Blc\Helper;


use Blc\Controller\ModuleManager;


class CheckerHelper
{
    /**
     * Get a reference to a specific checker.
     *
     * @uses ModuleManager::get_module()
     *
     * @param string $checker_id
     * @return Checker
     */
    static function get_checker($checker_id)
    {
        $manager = ModuleManager::getInstance();
        return $manager->get_module($checker_id, true, 'checker');
    }

    /**
     * Get a checker object that can check the specified URL.
     *
     * @param string $url
     * @return Checker|null
     */
    static function get_checker_for($url)
    {
        $parsed = @parse_url($url);

        $manager         = ModuleManager::getInstance();
        $active_checkers = $manager->get_active_by_category('checker');
        foreach ($active_checkers as $module_id => $module_data) {
            // Try the URL pattern in the header first. If it doesn't match,
            // we can avoid loading the module altogether.
            if (! empty($module_data['ModuleCheckerUrlPattern'])) {
                if (! preg_match($module_data['ModuleCheckerUrlPattern'], $url)) {
                    continue;
                }
            }

            $checker = $manager->get_module($module_id);

            if (! $checker) {
                continue;
            }

            // The can_check() method can perform more sophisticated filtering,
            // or just return true if the checker thinks matching the URL regex
            // is sufficient.
            if ($checker->can_check($url, $parsed)) {
                return $checker;
            }
        }

        $checker = null;
        return $checker;
    }
}
