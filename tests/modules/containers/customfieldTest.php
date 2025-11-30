<?php

namespace LinkChecker\Tests\modules\containers;


use PHPUnit\Framework\Attributes;
use PostMetaManager as Manager;
use Blc\Controller\ModuleManager;
use Blc\Container\PostMeta as PostMetaContainer;
use Blc\Abstract\ContainerManager;
use Blc\Controller\Link;
use Blc\Helper\ContainerHelper;


require_once BLC_DIRECTORY_LEGACY . '/modules/containers/custom_field.php';


#[Attributes\CoversClass(PostMetaContainer::class)]
#[Attributes\CoversClass(Manager::class)]
//naming for old classes
class customfieldTest extends dummyTest
{
    protected static string $moduleId = 'custom_field';
    protected static string $parserId = 'metadata';
    protected static array $containerData = ['custom_field' => true];
    protected static string $class = Manager::class;

    #[Attributes\Depends('testBootManager')]
    public function testMetaModified(ContainerManager $managerInstance)
    {
        $post = $this->getAPost();

        $containerInstance = ContainerHelper::get_container([static::$moduleId, $post->ID]);
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

    public function testParserAsChild()
    {
        $moduleManager = ModuleManager::getInstance();

        $moduleManager->deactivate(static::$moduleId);
        $parserActive = $moduleManager->is_active(static::$moduleId);
        $this->assertFalse($parserActive);
        $moduleManager->deactivate(static::$parserId);
        $parserActive = $moduleManager->is_active(static::$parserId);
        $this->assertFalse($parserActive);

        $moduleManager->activate(static::$moduleId);
        $parserActive = $moduleManager->is_active(static::$parserId);
        $this->assertTrue($parserActive);
        $moduleManager->deactivate(static::$moduleId);
        $parserActive = $moduleManager->is_active(static::$parserId);
        $this->assertFalse($parserActive);
    }


    public function testFootnotes()
    {

        $post = $this->getAPost(['meta_key' => 'footnotes']);

        $containerInstance = ContainerHelper::get_container([static::$moduleId, $post->ID]);
        $this->assertNotNull($containerInstance, 'Footnotes are reuquired for this test. Add them to a post:');
        $footnotes = $containerInstance->get_field('footnotes');
        $this->assertNotEmpty($footnotes, 'Footnotes are reuquired for this test. Add them to: ' . $post->ID);
        $containerInstance->synch();
        // edit_link($field_name, $parser, $new_url, $old_url = '', $old_raw_url = '', $new_text = null)

        $instance = $this->getInstance(['container_field' => 'footnotes']);
        
        $link = new Link(intval($instance['link_id']));
        $new_url = 'https://200.invalid/' . uniqid();
        $rez = $link->edit($new_url);
        $new_url_rez = $rez['new_link'];

        $this->assertSame($new_url , $new_url_rez->url,"Failed for container: {$instance['container_id']}");
       
    }
}
