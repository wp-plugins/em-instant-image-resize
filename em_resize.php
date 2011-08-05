<?php
/*
Plugin Name: EM Instant Image Resize
Plugin URI: www.expressmanor.co.za/plugins/instant-image-resize
Description: Allows on-the-fly image resizing from the template into cached directories.
Version: 1.0.1
Author: Kurt Ferreira
Author URI: www.expressmanor.co.za
License: GPL
Copyright: None

PLEASE NOTE: This plugin was HIGHLY inspired from the ImgSizer plugin for ExpressionEngine.
No harm was intended by providing Wordpress with similar awesomeness. Please don't sue me.

*/

class em_iiresize
{ 		
	function em_iiresize()
	{				
		return true;
	}
			
	/*---------------------------------------------------------------------------------------------
	 *
	 * Resize the image and cache to folder
	 * 
	 ---------------------------------------------------------------------------------------------*/				
	function resize( $image, $width, $height, $quality, $crop, $fill, $force )
	{
		$cached_file = '';
		
		if( array_key_exists( 'DOCUMENT_ROOT', $_ENV ) )		
			$base_path = $_ENV['DOCUMENT_ROOT']."/";
		else
			$base_path = $_SERVER['DOCUMENT_ROOT']."/";
			
		$base_path 		= str_replace( "\\", "/", $base_path );
		$base_path 		= $this->clean_path( $base_path );		
		$image 			= $this->clear_absolute( $image );		
		$original_file  = $base_path.$image;		
		
		if( file_exists( $original_file ) )
		{
			// Make sure the cache folder exists, then check for a cached copy
			$cache_folder = dirname( $original_file ).'/_cache';
			if( !file_exists( $cache_folder ) ) 
			{
				if( !mkdir( $cache_folder, 0755, true ) )
					return "Warning[ii_resize]: Cannot create the cache folder";					
			
				if( !is_writable( $cache_folder ) )
					return "Warning[ii_resize]: Cannot write to cache folder";									
			} 
			
			// Calculate the size of the cached image request and see if it exists
			if( !$crop ) 				
				$size = array( $width, $height );	
			else 
				$size = $this->wanted_size( $original_file, $width, $height );
			
			if( !$force && file_exists( $cache_folder.'/'.$size[0].'x'.$size[1].'-'.basename( $image ) ) )
			{
				$cached_file = '/'.str_replace( $base_path, '', $cache_folder.'/'.$size[0].'x'.$size[1].'-'.basename( $image ) );
			} else 
			{	
				$this->make_file( $original_file, $cache_folder.'/'.$size[0].'x'.$size[1].'-'.basename( $image ), $width, $height, $quality, $crop, $fill );				
				$cached_file = '/'.str_replace( $base_path, '', $cache_folder.'/'.$size[0].'x'.$size[1].'-'.basename( $image ) );				
			}			
		} else {
			return "Warning[ii_resize]: File does not exists[".$original_file."]";
		} 
		
		return $cached_file;
	}	
	
	/*---------------------------------------------------------------------------------------------
	 *
	 * Some simple housekeeping internal functions
	 * 
	 ---------------------------------------------------------------------------------------------*/
	function make_file( $original, $output, $width, $height, $quality, $crop, $fill )
	{
		// Try increase memory first(do this incrementally so we get the largest possible memory allocation)
		@ini_set("memory_limit","12M");
		@ini_set("memory_limit","16M");
		@ini_set("memory_limit","32M");
		@ini_set("memory_limit","64M");	
		
		// ensure we're working with int's here
		$width = intval( $width );
		$height = intval( $height );
		$quality = intval( $quality );

		$fillcolour = $this->hex_to_rgb( $fill );
	 
		// First determine what file type we are opening		
		$info  			= getimagesize( $original );
		$original_image = 0;
		
		switch(	$info[2] ) 
		{
			case IMAGETYPE_GIF:
				$original_image = imagecreatefromgif( $original );
				break;
			case IMAGETYPE_JPEG:
				$original_image = imagecreatefromjpeg( $original );
				break;
			case IMAGETYPE_PNG:
				$original_image = imagecreatefrompng( $original );
				break;
			default:
				return "Warning[ii_resize]: Unknown file type";				
		}
		
		// Create the new image
		$new_image = imagecreatetruecolor( $width, $height );
		
		// Will we need to crop the image		
		if( ( intval( $info[0] ) / intval( $info[1] ) ) != ( intval( $width ) / intval( $height ) ) )
			$needs_crop = true;

		// Check for transparent images
		if( $info[2] == IMAGETYPE_GIF || $info[2] == IMAGETYPE_PNG )
		{			
			$alpha_index = imagecolortransparent( $original_image );
			
			// Set alpha index
			if( $alpha_index >= 0 ) 
			{				
				// Retreive the alpha index and set it as the background
				$alpha    	 = imagecolorsforindex( $original_image, $alpha_index );
  
				$alpha_index = imagecolorallocate( $new_image, $alpha['red'], $alpha['green'], $alpha['blue'] );
  
				imagefill( $new_image, 0, 0, $alpha_index );
  
				imagecolortransparent( $new_image, $alpha_index );
			} else if( $info[2] == IMAGETYPE_PNG )
			{				
				// Create and completely fill all PNG's with a transparent background
				imagealphablending( $new_image, false );
  			
				$color = imagecolorallocatealpha( $new_image, 0, 0, 0, 127 );
  			
				imagefill( $new_image, 0, 0, $color );
  
				imagesavealpha( $new_image, true );
			}
 		} else {
			$color = imagecolorallocate ( $new_image, $fillcolour[0], $fillcolour[1], $fillcolour[2] );
			imagefill( $new_image, 0, 0, $color );
		}
 
		if( !$needs_crop ) {
			imagecopyresampled( $new_image, $original_image, 0, 0, 0, 0, $width, $height, $info[0], $info[1] );
		} else 
		{
			$modified_width  = $info[0] / $width;
			$modified_height = $info[1] / $height;
			$adjusted_width  = $width;
			$adjusted_height = $height;
			$crop_width		 = 0;
			$crop_height	 = 0;

			if( $crop )
			{			
				// Get the dimension closest to the new dimension and scale down to it, then crop the other dimension off
				// at the center.	
				if( $modified_width > $modified_height || $modified_width == $modified_height )
				{					
					$adjusted_width = round( $info[0] / $modified_height );
					$half_width 	= round( $adjusted_width / 2 );
					$crop_width 	= $half_width - round( $width / 2 );				
				} else if( ( $info[0] < $info[1] ) || ( $info[0] == $info[1] ) )
				{
					$adjusted_height = round( $info[1] / $modified_width );
					$half_height 	 = round( $adjusted_height / 2 );
					$crop_height 	 = $half_height - round( $height / 2 );
				}
				
				imagecopyresampled( $new_image, $original_image, -$crop_width, -$crop_height, 0, 0, $adjusted_width, $adjusted_height, $info[0], $info[1] );
			} else if( !$crop ) 
			{	
				// Resize, but don't crop and set the background to the requested fill colour.							
				if( $modified_width > $modified_height || $modified_width == $modified_height ) 
				{ 
					$aspect = $modified_width / $modified_height;
					$adjusted_height = round( $height / $aspect );				
					$offset_height = ( $adjusted_height - $height ) / 2;
				} else
				{		
					$aspect = $modified_height / $modified_width;
					$adjusted_width = round( $width / $aspect );
					$offset_width = ( $adjusted_width - $width ) / 2;
				}
				
				imagecopyresampled( $new_image, $original_image, -$offset_width, -$offset_height, 0, 0, $adjusted_width, $adjusted_height, $info[0], $info[1] );
			}
		}
		// Save the image
		switch( $info[2] ) 
		{
			case IMAGETYPE_GIF:
				imagegif( $new_image, $output );
				break;
			case IMAGETYPE_JPEG:
				imagejpeg( $new_image, $output, $quality );
				break;
			case IMAGETYPE_PNG:
				imagepng( $new_image, $output );
				break;
			default:
				return "Warning[ii_resize]: Unknown file type";				
		}
	}
	
	function wanted_size( $src, $width, $height )
	{
		list( $original_width, $original_height ) = getimagesize( $src );
		$scale_width  = $width;
		$scale_height = $height;
		
		if( $height == 'auto' || $width == 'auto' )
		{
			// Proportional scaling
			$use_width = ( $width == 'auto' ) ? true : false;
			if( $use_width ) 
			{
				$scale_factor 	= $original_width / $width;
				$scale_width 	= $width;
				$scale_height 	= $original_height * $scale_factor;
			} else
			{
				$scale_factor 	= $original_height / $height;
				$scale_width 	= $original_width * $scale_factor;
				$scale_height 	= $height;
			}
		}
		
		return array( $scale_width, $scale_height );
	}
	
	function clean_path( $path )
	{
		$tmp_path = '';
		for( $i = 0; $i < strlen($path); $i++ )
		{
			if( isset( $path[$i + 1] ) && $path[$i] == '/' && $path[$i + 1] == $path[$i] )
				continue;
			else
				$tmp_path .= $path[$i];
		}
		
		return $tmp_path;
	}
	
	function clear_absolute( $path )
	{
		if( !isset( $path[0] ) )
			return $path;			
		
		if( strstr( $path, 'http://' ) || strstr( $path, 'https://' ) )
		{
			$protocol = ( stristr( 'https', $_SERVER['SERVER_PROTOCOL'] ) )? 'https://' : 'http://';
			$wordpress_domain = $protocol.( ( strstr( $path, 'www.' ) )?'www.'.$_SERVER['SERVER_NAME']:$_SERVER['SERVER_NAME'] );
			
			$path = str_replace( $wordpress_domain, '', $path );		
		}
						
		if( $path[0] == '/' )
			$path = substr( $path, 1 );
			
		return $path;
	}
	
	function hex_to_rgb($hex)
	{
		if( $hex[0] == '#' )
			$hex = substr( $hex, 1 );
			
		if (strlen($hex) == 6)
			list($r, $g, $b) = array($hex[0].$hex[1], $hex[2].$hex[3], $hex[4].$hex[5]);
		else if (strlen($hex) == 3)
			list($r, $g, $b) = array($hex[0].$hex[0], $hex[1].$hex[1], $hex[2].$hex[2]);
		else return array(0, 0, 0);
		
		return array(hexdec($r), hexdec($g), hexdec($b));
	}
}

/*---------------------------------------------------------------------------------------------
 * Resize Function(called from template)
 * @args 
 *		$src(string) 		The relative path to the image
 *		$width(int/string)	The maximum width of the image(or 'auto' for proportional scaling)
 *		$height(int/string) The maximum height of the image(or 'auto' for proportional scaling
 *		$quality(int)		The quality(jpeg) of the resized image
 *		$crop(boolean)		Crop the image to requested size if scaling doesn't fit
 *		$force(boolean)		Force a new image resize(ignores cache)
 *		$fill(string)		The fill colour if the image isn't cropped(full hex value), N/A for transparent images
 * @return URL to resized image(within cache folder)
 *
 ---------------------------------------------------------------------------------------------*/
function em_resize( $src, $width, $height = 'auto', $quality = 95, $crop=true, $fill="CCCCCC", $force=false )
{
	$img_resize = new em_iiresize();	
	return $img_resize->resize( $src, $width, $height, $quality, $crop, $fill, $force );
}