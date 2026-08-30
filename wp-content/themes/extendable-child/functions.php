<?php
// Charge les styles du theme parent (Extendable)
add_action( 'wp_enqueue_scripts', function () {
      wp_enqueue_style(
                'extendable-parent-style',
                get_template_directory_uri() . '/style.css'
            );

      // Styles du footer uniquement (redesign Souss Actualites)
      wp_enqueue_style(
                'souss-actualites-footer',
                get_stylesheet_directory_uri() . '/assets/css/footer.css',
                array( 'extendable-parent-style' ),
                '1.0'
            );
} );

// Schema NewsArticle (JSON-LD) + Open Graph pour Google Discover
add_action( 'wp_head', function () {
      if ( ! is_single() ) {
                return;
      }

               $post_id    = get_the_ID();
      $title      = get_the_title( $post_id );
      $url        = get_permalink( $post_id );
      $modified   = get_the_modified_date( 'c', $post_id );
      $published  = get_the_date( 'c', $post_id );
      $author     = get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) );
      $image_url  = get_the_post_thumbnail_url( $post_id, 'full' );
      $image_meta = $image_url ? wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' ) : false;

               if ( $image_url ) {
                         echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . "\n";
                         if ( $image_meta ) {
                                       echo '<meta property="og:image:width" content="' . esc_attr( $image_meta[1] ) . '">' . "\n";
                                       echo '<meta property="og:image:height" content="' . esc_attr( $image_meta[2] ) . '">' . "\n";
                         }
               }
      echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
      echo '<meta property="og:type" content="article">' . "\n";
      echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";

               $schema = array(
                         '@context'         => 'https://schema.org',
                         '@type'            => 'NewsArticle',
                         'headline'         => $title,
                         'datePublished'    => $published,
                         'dateModified'     => $modified,
                         'mainEntityOfPage' => array(
                                       '@type' => 'WebPage',
                                       '@id'   => $url,
                                   ),
                         'author'    => array(
                                       '@type' => 'Person',
                                       'name'  => $author,
                                   ),
                         'publisher' => array(
                                       '@type' => 'Organization',
                                       'name'  => get_bloginfo( 'name' ),
                                       'logo'  => array(
                                                         '@type' => 'ImageObject',
                                                         'url'   => get_site_icon_url( 512 ),
                                                     ),
                                   ),
                     );

               if ( $image_url ) {
                         $schema['image'] = array( $image_url );
               }

               echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
} );
