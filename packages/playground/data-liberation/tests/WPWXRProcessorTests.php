<?php

use PHPUnit\Framework\TestCase;

class WPWXRProcessorTests extends TestCase {
    
    public function test_simple_wxr() {
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

    public function test_woo_products_wxr() {
        $importer = new WP_WXR_Processor(
            WP_XML_Processor::from_string(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss>
                <channel>
                    <item>
                        <title>vneck-tee-2.jpg</title>
                        <link>https://stylish-press.wordpress.org/?attachment_id=31</link>
                        <pubDate>Wed, 16 Jan 2019 13:01:56 +0000</pubDate>
                        <dc:creator>shopmanager</dc:creator>
                        <guid isPermaLink="false">https://raw.githubusercontent.com/wordpress/blueprints/stylish-press/blueprints/stylish-press/woo-product-images/vneck-tee-2.jpg</guid>
                        <description/>
                        <content:encoded><![CDATA[]]></content:encoded>
                        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
                        <wp:post_id>31</wp:post_id>
                        <wp:post_date>2019-01-16 13:01:56</wp:post_date>
                        <wp:post_date_gmt>2019-01-16 13:01:56</wp:post_date_gmt>
                        <wp:comment_status>open</wp:comment_status>
                        <wp:ping_status>closed</wp:ping_status>
                        <wp:post_name>vneck-tee-2-jpg</wp:post_name>
                        <wp:status>inherit</wp:status>
                        <wp:post_parent>6</wp:post_parent>
                        <wp:menu_order>0</wp:menu_order>
                        <wp:post_type>attachment</wp:post_type>
                        <wp:post_password/>
                        <wp:is_sticky>0</wp:is_sticky>
                        <wp:attachment_url>https://raw.githubusercontent.com/wordpress/blueprints/stylish-press/blueprints/stylish-press/woo-product-images/vneck-tee-2.jpg</wp:attachment_url>
                        <wp:postmeta>
                            <wp:meta_key>_wc_attachment_source</wp:meta_key>
                            <wp:meta_value><![CDATA[https://raw.githubusercontent.com/wordpress/blueprints/stylish-press/blueprints/stylish-press/woo-product-images/vneck-tee-2.jpg]]></wp:meta_value>
                        </wp:postmeta>
                    </item>
                </channel>
            </rss>
            XML
            )
        );
        $this->assertEquals(
            new WXR_Object('post', [
                'post_title' => 'vneck-tee-2.jpg',
                'ID' => '31',
                'guid' => 'https://raw.githubusercontent.com/wordpress/blueprints/stylish-press/blueprints/stylish-press/woo-product-images/vneck-tee-2.jpg',
                'post_date' => '2019-01-16 13:01:56',
                'post_author' => 'shopmanager',
                'post_excerpt' => '',
                'post_date_gmt' => '2019-01-16 13:01:56',
                'comment_status' => 'open',
                'ping_status' => 'closed',
                'post_name' => 'vneck-tee-2-jpg',
                'post_status' => 'inherit',
                'post_parent' => '6',
                'menu_order' => '0',
                'post_type' => 'attachment',
                'post_password' => false,
                'attachment_url' => 'https://raw.githubusercontent.com/wordpress/blueprints/stylish-press/blueprints/stylish-press/woo-product-images/vneck-tee-2.jpg',
            ]),
            $importer->next_object()
        );

        $this->assertEquals(
            new WXR_Object('post_meta', [
                'meta_key' => '_wc_attachment_source',
                'meta_value' => 'https://raw.githubusercontent.com/wordpress/blueprints/stylish-press/blueprints/stylish-press/woo-product-images/vneck-tee-2.jpg',
            ]),
            $importer->next_object()
        );
    }

}
