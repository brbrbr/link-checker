<?php

/**
 * Integrations
 *
 * @package Broken_Link_Checker
 * 
 * @since 2.4.3
 * not used for reference was integrations/integrations.php
 */

namespace Blc\Integrations;


class Integrations
{

	/**
	 * Blc Integrations constructor.
	 */
	public function __construct()
	{

		if (class_exists('\SiteOrigin_Panels')) {

			SiteOrigin::instance();
		}
	}
}
new Integrations();
