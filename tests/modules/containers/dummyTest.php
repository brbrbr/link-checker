<?php

namespace LinkChecker\Tests\modules\containers;

use LinkChecker\Tests\TestCase;
use PHPUnit\Framework\Attributes;
use blcDummyManager as Manager;
use blcPostTypeOverlord as Overlord;
use Blc\Util\ConfigurationManager;
use Blc\Controller\ModuleManager;
use Blc\Abstract\ContainerManager;
use Blc\Abstract\Container;

require_once BLC_DIRECTORY_LEGACY . '/modules/containers/dummy.php';

#[Attributes\CoversClass(Base::class)]
#[Attributes\CoversClass(Utility::class)]
//naming for old classes
class dummyTest extends TestCase
{
    protected static string $moduleId = 'dummy';
    protected static array $containerData = ['dummy' => true];
    protected static string $class = Manager::class;


    public function testBootManager(): ContainerManager
    {

        $this->expectNotToPerformAssertions();
        $pluginConfig = ConfigurationManager::getInstance();
        $pluginConfig->options['custom_fields'] = [
            'html:html-test',
            'url'
        ];
        $pluginConfig->options['active_modules'] = [];
        $moduleManager = ModuleManager::getInstance();
        $moduleManager->activate(static::$moduleId);
        $managerInstance = new static::$class(static::$moduleId, [], $pluginConfig, $moduleManager);
        $overlord = Overlord::getInstance();
        $overlord->post_type_enabled('post');

        return $managerInstance;
    }

    #[Attributes\Depends('testBootManager')]
    public function testLoadContainer(ContainerManager $managerInstance)
    {
        $this->expectNotToPerformAssertions();
        $containerInstance = $managerInstance->get_container(static::$containerData);
        return $containerInstance;
    }

    #[Attributes\Depends('testLoadContainer')]
    public function testSyncContainer(Container $containerInstance)
    {
        $this->expectNotToPerformAssertions();
        $containerInstance->synch();
    }

    #[Attributes\Depends('testLoadContainer')]
    public function testSyncState(Container $containerInstance)
    {

        $containerInstance->mark_as_synched();
        $arr = $containerInstance->get_synched_state();
        $this->assertNotempty($arr);
        $this->assertEquals(1, $arr['synched']);
        $containerInstance->mark_as_unsynched();
        $arr = $containerInstance->get_synched_state();
        $this->assertNotempty($arr);
        $this->assertEquals(0, $arr['synched']);
    }

    #[Attributes\Depends('testBootManager')]
    public function testResyncContainer(ContainerManager $managerInstance)
    {
        $this->expectNotToPerformAssertions();
        $managerInstance->resynch();
    }
    #[Attributes\Depends('testBootManager')]
    public function testResyncForceContainer(ContainerManager $managerInstance)
    {

        $this->expectNotToPerformAssertions();
        $managerInstance->resynch(true);
    }
}
