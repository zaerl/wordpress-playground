<?php

use PHPUnit\Framework\TestCase;

class WPWXRProcessorTests extends TestCase {
    
    public function test_process() {
        $importer = new WP_WXR_Processor(
            WP_XML_Processor::from_string(file_get_contents(__DIR__ . '/fixtures/wxr-simple.xml'))
        );
        $this->assertEquals(
            new WXR_Object('site_option', ['blogname', 'My WordPress Website']),
            $importer->next_object()
        );

        $this->assertEquals(
            new WXR_Object('site_option', ['siteurl', 'https://playground.internal/path']),
            $importer->next_object()
        );

        $this->assertEquals(
            new WXR_Object('site_option', ['home', 'https://playground.internal/path']),
            $importer->next_object()
        );

        $this->assertEquals(
            new WXR_Object('user', [
                'user_login' => 'admin',
                'user_email' => 'admin@localhost.com',
                'display_name' => 'admin',
                'first_name' => '',
                'last_name' => '',
                'ID' => 1
            ]),
            $importer->next_object()
        );
        
        $this->assertEquals(
            new WXR_Object('post', [
                'post_title' => '"The Road Not Taken" by Robert Frost',
                'guid' => 'https://playground.internal/path/?p=1',
                'post_date' => '2024-06-05 16:04:48',
                'post_author' => 'admin',
                'post_excerpt' => '',
                'post_content' => '<!-- wp:paragraph -->
<p>Two roads diverged in a yellow wood,<br>And sorry I could not travel both</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>
<a href="https://playground.internal/path/one">One</a> seemed great, but <a href="https://playground.internal/path-not-taken">the other</a> seemed great too.
There was also a <a href="https://w.org">third</a> option, but it was not as great.

playground.internal/path/one was the best choice.
https://playground.internal/path-not-taken was the second best choice.
</p>
<!-- /wp:paragraph -->',
                'ID' => '10',
                'post_date_gmt' => '2024-06-05 16:04:48',
                'post_modified' => '2024-06-10 12:28:55',
                'post_modified_gmt' => '2024-06-10 12:28:55',
                'comment_status' => 'open',
                'ping_status' => 'open',
                'post_name' => 'hello-world',
                'post_status' => 'publish',
                'post_parent' => '0',
                'menu_order' => '0',
                'post_type' => 'post',
                'post_password' => '',
                'is_sticky' => '0',
                'terms' => [
                    'category' => ['Uncategorized']
                ],
            ]),
            $importer->next_object()
        );

        $this->assertEquals(
            new WXR_Object('post_meta', [
                'meta_key' => '_pingme',
                'meta_value' => '1',
            ]),
            $importer->next_object()
        );

        $this->assertEquals(
            new WXR_Object('post_meta', [
                'meta_key' => '_encloseme',
                'meta_value' => '1',
            ]),
            $importer->next_object()
        );

        $this->assertFalse($importer->next_object());
    }

}
