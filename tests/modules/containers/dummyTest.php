<?php

namespace LinkChecker\Tests\modules\containers;

use LinkChecker\Tests\TestCase;
use PHPUnit\Framework\Attributes;
use blcDummyManager as Manager;
use blcPostTypeOverlord as Overlord;
use Blc\Container\Dummy as DummyContainer;
use Blc\Util\ConfigurationManager;
use Blc\Controller\ModuleManager;
use Blc\Abstract\ContainerManager;
use Blc\Abstract\Container;

require_once BLC_DIRECTORY_LEGACY . '/modules/containers/dummy.php';

#[Attributes\CoversClass(DummyContainer::class)]
#[Attributes\CoversClass(Manager::class)]
//naming for old classes
class dummyTest extends TestCase
{
    protected static string $moduleId = 'dummy';
    protected static array $containerData = ['dummy' => true];
    protected static string $class = Manager::class;
    protected  $pluginConfig;
    protected $storedpluginOptions = [];

    public function setUp(): void
    {
        $this->pluginConfig = ConfigurationManager::getInstance();
        $this->storedpluginOptions = $this->pluginConfig->get_options();
      //  file_put_contents('/tmp/link-checker-'.microtime(true) . '.json',json_encode($this->storedpluginOptions ,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    public function tearDown(): void
    {
       
       
       // $this->storedpluginOptions = json_decode(file_get_contents(BLC_BASENAME_DIR . '/tests/assets/settings.json'), true);
      
        $this->pluginConfig->set_options($this->storedpluginOptions);
        $this->pluginConfig->save_options();
    }


    public function testBootManager(): ContainerManager
    {

        $this->expectNotToPerformAssertions();

        $this->pluginConfig->options['custom_fields'] = [
            'html:html-test',
            'url'
        ];
        //   $pluginConfig->options['active_modules'] = [];
        $moduleManager = ModuleManager::getInstance();
        $moduleManager->refresh_active_module_cache();
        $moduleManager->activate(static::$moduleId);
        $managerInstance = new static::$class(static::$moduleId, [], $this->pluginConfig, $moduleManager);
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
