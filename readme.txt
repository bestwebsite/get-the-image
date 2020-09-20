=== Get the Image ===

Contributors: bestwebsite
Tags: image, images, thumbnail
Requires at least: 3.9
Tested up to: 4.8.2
Requires PHP: 5.2
Stable tag: 1.1.0

An easy-to-use image script for adding things such as thumbnail, slider, gallery, and feature images.

== Description ==

Get the Image is one of the most advanced thumbnail/image scripts ever created for WordPress.

### Plugin Development

If you're a theme author, plugin author, or just a code hobbyist, you can follow the development of this plugin on its.

== Frequently Asked Questions ==

### How does it grab images?

1. Looks for an image by custom field (one of your choosing).
2. If no image is added by custom field, check for a featured image.
3. If no image is found, it grabs an image attached to your post.
4. If no image is attached, it can extract an image from your post content (off by default).
5. If no image is found at this point, it will fall back to a default image (not set by default).

### How do I add it to my theme?

There are several methods, but in general, you would use this call within The Loop.

```
<?php if ( function_exists( 'get_the_image' ) ) get_the_image(); ?>
```

To see all methods and options, refer to the `readme.md` file included with the plugin download.

== Screenshots ==

1. Slider plus thumbnails.
2. Portfolio images.
3. Gallery-style thumbnails.

== Changelog ==

=1.0=
Intial Version