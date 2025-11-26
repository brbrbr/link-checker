<?php

namespace LinkChecker\Tests\modules\containers;


use PHPUnit\Framework\Attributes;
use PostMetaManager as Manager;
use Blc\Util\ConfigurationManager;
use Blc\Controller\ModuleManager;
use Blc\Abstract\ContainerManager;
use Blc\Abstract\Container;
use Blc\Helper\ContainerHelper;


require_once BLC_DIRECTORY_LEGACY . '/modules/containers/custom_field.php';


#[Attributes\CoversClass(Base::class)]
#[Attributes\CoversClass(Utility::class)]
//naming for old classes
class customfieldTest extends dummyTest
{
    protected static string $moduleId = 'custom_field';
    protected static array $containerData = ['custom_field' => true];
    protected static string $class = Manager::class;

    #[Attributes\Depends('testBootManager')]
    public function testMetaModified(ContainerManager $managerInstance)
    {
        $post = $this->getAPost();
        $containerInstance = ContainerHelper::get_container(array(static::$moduleId, $post->ID));
        $containerInstance->mark_as_synched();
        $arr = $containerInstance->get_synched_state();
        $this->assertNotempty($arr);
        $this->assertEquals(1, $arr['synched']);
        $this->clearLog();

        $managerInstance->meta_modified(0, $post->ID, 'html-test');
        $this->assertHasLog();
        $arr = $containerInstance->get_synched_state();
        $this->assertNotempty($arr);
        $this->assertEquals(0, $arr['synched']);
    }
}
