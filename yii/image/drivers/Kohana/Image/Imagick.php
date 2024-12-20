<?php
namespace yii\image\drivers;

use yii\base\ErrorException;
use \Imagick;
use \ImagickPixel;
/**
 * Support for image manipulation using [Imagick](http://php.net/Imagick).
 *
 * @package    Kohana/Image
 * @category   Drivers
 * @author     Tamas Mihalik tamas.mihalik@gmail.com
 * @copyright  (c) 2009-2012 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */

class Kohana_Image_Imagick extends Kohana_Image {

        /**
         * @var  Imagick  image magick object
         */
        protected $im;

        /**
         * Checks if ImageMagick is enabled.
         *
         * @throws  ErrorException
         * @return  boolean
         */
        public static function check()
        {
                if ( ! extension_loaded('imagick'))
                {
                        throw new ErrorException('Imagick is not installed, or the extension is not loaded');
                }

                return Image_Imagick::$_checked = TRUE;
        }

        /**
         * Runs [Image_Imagick::check] and loads the image.
         *
         * @return  void
         * @throws  ErrorException
         */
        public function __construct($file)
        {
                if ( ! Image_Imagick::$_checked)
                {
                        // Run the install check
                        Image_Imagick::check();
                }

                parent::__construct($file);

                $this->im = new Imagick;
                $this->im->readImage($file);

                // Force RGB colorspace if we are using non-RGB
                $imageColorspace = $this->im->getImageColorspace();
                if ($imageColorspace !== Imagick::COLORSPACE_RGB &&
                    $imageColorspace !== Imagick::COLORSPACE_SRGB
                ) {
                    $imageColorspace = Imagick::COLORSPACE_RGB;
                }
                if ($this->im->getImageColorspace() !== $imageColorspace) {
                    $this->im->transformImageColorspace($imageColorspace);
                }
                $this->im->setColorspace($imageColorspace);

                if ( ! $this->im->getImageAlphaChannel())
                {
                        // Force the image to have an alpha channel
                        $this->im->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                }
        }

        /**
         * Destroys the loaded image to free up resources.
         *
         * @return  void
         */
        public function __destruct()
        {
                $this->im->clear();
                $this->im->destroy();
        }

        /**
         * Scales an image using resizeImage for best quality @link http://php.net/manual/en/imagick.resizeimage.php
         * but lower speed, or scaleImage for best speed and lower quality @link http://php.net/manual/en/imagick.scaleimage.php
         * @param int $width
         * @param int $height
         * @param int $quality
         * @return bool
         */
        protected function _do_resize($width, $height, $quality)
        {
            if($quality == 100) {
                if ($this->im->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1)) {
                    // Reset the width and height
                    $this->width = $this->im->getImageWidth();
                    $this->height = $this->im->getImageHeight();

                    return TRUE;
                }
            }else{
                if ($this->im->sampleImage($width, $height)) {
                    // Reset the width and height
                    $this->width = $this->im->getImageWidth();
                    $this->height = $this->im->getImageHeight();

                     return TRUE;
                }
            }

                return FALSE;
        }

        /**
         * Adaptation the image.
         *
         * @param   integer  $width      image width
         * @param   integer  $height     image height
         * @param   integer  $bg_width   background width
         * @param   integer  $bg_height  background height
         * @param   integer  $offset_x   offset from the left
         * @param   integer  $offset_y   offset from the top
         */
        protected function _do_adapt($width, $height, $bg_width, $bg_height, $offset_x, $offset_y)
        {
                $image = new Imagick();
                $image->newImage($bg_width, $bg_height, "none");
                $image->compositeImage($this->im, Imagick::COMPOSITE_ADD, $offset_x, $offset_y);
                $this->im->clear();
                $this->im->destroy();
                $this->im = $image;
                $this->width = $bg_width;
                $this->height = $bg_height;
        }

        protected function _do_crop($width, $height, $offset_x, $offset_y)
        {
                if ($this->im->cropImage($width, $height, $offset_x, $offset_y))
                {
                        // Reset the width and height
                        $this->width = $this->im->getImageWidth();
                        $this->height = $this->im->getImageHeight();

                        // Trim off hidden areas
                        $this->im->setImagePage($this->width, $this->height, 0, 0);

                        return TRUE;
                }

                return FALSE;
        }

        protected function _do_rotate($degrees)
        {
                if ($this->im->rotateImage(new ImagickPixel('transparent'), $degrees))
                {
                        // Reset the width and height
                        $this->width = $this->im->getImageWidth();
                        $this->height = $this->im->getImageHeight();

                        // Trim off hidden areas
                        $this->im->setImagePage($this->width, $this->height, 0, 0);

                        return TRUE;
                }

                return FALSE;
        }

        protected function _do_flip($direction)
        {
                if ($direction === Image::HORIZONTAL)
                {
                        return $this->im->flopImage();
                }
                else
                {
                        return $this->im->flipImage();
                }
        }

        protected function _do_sharpen($amount)
        {
                // IM not support $amount under 5 (0.15)
                $amount = ($amount < 5) ? 5 : $amount;

                // Amount should be in the range of 0.0 to 3.0
                $amount = ($amount * 3.0) / 100;

                return $this->im->sharpenImage(0, $amount);
        }

        protected function _do_reflection($height, $opacity, $fade_in)
        {
                // Clone the current image and flip it for reflection
                $reflection = $this->im->clone();
                $reflection->flipImage();

                // Crop the reflection to the selected height
                $reflection->cropImage($this->width, $height, 0, 0);
                $reflection->setImagePage($this->width, $height, 0, 0);

                // Select the fade direction
                $direction = array('transparent', 'black');

                if ($fade_in)
                {
                        // Change the direction of the fade
                        $direction = array_reverse($direction);
                }

                // Create a gradient for fading
                $fade = new Imagick;
                $fade->newPseudoImage($reflection->getImageWidth(), $reflection->getImageHeight(), vsprintf('gradient:%s-%s', $direction));

                // Apply the fade alpha channel to the reflection
                $reflection->compositeImage($fade, Imagick::COMPOSITE_DSTOUT, 0, 0);

                // NOTE: Using setImageOpacity will destroy alpha channels!
                $reflection->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);

                // Create a new container to hold the image and reflection
                $image = new Imagick;
                $image->newImage($this->width, $this->height + $height, new ImagickPixel);

                // Force the image to have an alpha channel
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

                // Force the background color to be transparent
                // $image->setImageBackgroundColor(new ImagickPixel('transparent'));

                // Place the image and reflection into the container
                if ($image->compositeImage($this->im, Imagick::COMPOSITE_SRC, 0, 0)
                AND $image->compositeImage($reflection, Imagick::COMPOSITE_OVER, 0, $this->height))
                {
                        // Replace the current image with the reflected image
                        $this->im = $image;

                        // Reset the width and height
                        $this->width = $this->im->getImageWidth();
                        $this->height = $this->im->getImageHeight();

                        return TRUE;
                }

                return FALSE;
        }

        protected function _do_watermark(Kohana_Image $image, $offset_x, $offset_y, $opacity)
        {
                // Convert the Image intance into an Imagick instance
                $watermark = new Imagick;
                $watermark->readImageBlob($image->render(), $image->file);
                if ($this->im->getImageColorspace() !== $this->im->getColorspace()) {
                    $watermark->transformImageColorspace($this->im->getColorspace());
                }

                if ($watermark->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_ACTIVATE)
                {
                        // Force the image to have an alpha channel
                        $watermark->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
                }

                if ($opacity < 100)
                {
                        // NOTE: Using setImageOpacity will destroy current alpha channels!
                        $watermark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);
                }

                // Apply the watermark to the image
                return $this->im->compositeImage($watermark, Imagick::COMPOSITE_DISSOLVE, $offset_x, $offset_y);
        }

        protected function _do_background($r, $g, $b, $opacity)
        {
                // Create a RGB color for the background
                $color = sprintf('rgb(%d, %d, %d)', $r, $g, $b);

                // Create a new image for the background
                $background = new Imagick;
                $background->newImage($this->width, $this->height, new ImagickPixel($color));

                if ( ! $background->getImageAlphaChannel())
                {
                        // Force the image to have an alpha channel
                        $background->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                }

                // Clear the background image
                $background->setImageBackgroundColor(new ImagickPixel('transparent'));

                // NOTE: Using setImageOpacity will destroy current alpha channels!
                $background->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);

                if ($background->compositeImage($this->im, Imagick::COMPOSITE_DISSOLVE, 0, 0))
                {
                        // Replace the current image with the new image
                        $this->im = $background;

                        return TRUE;
                }

                return FALSE;
        }

        protected function _do_save($file, $quality)
        {
                // Get the image format and type
                list($format, $type) = $this->_get_imagetype(pathinfo($file, PATHINFO_EXTENSION));

                // Set the output image type
                $this->im->setFormat($format);

                // Set the output quality
                $this->im->setImageCompressionQuality($quality);

                if ($this->im->writeImage($file))
                {
                        // Reset the image type and mime type
                        $this->type = $type;
                        $this->mime = image_type_to_mime_type($type);

                        return TRUE;
                }

                return FALSE;
        }

        protected function _do_render($type, $quality)
        {
                // Get the image format and type
                list($format, $type) = $this->_get_imagetype($type);

                // Set the output image type
                $this->im->setFormat($format);

                // Set the output quality
                $this->im->setImageCompressionQuality($quality);

                // Reset the image type and mime type
                $this->type = $type;
                $this->mime = image_type_to_mime_type($type);

                return (string) $this->im;
        }

        /**
         * Get the image type and format for an extension.
         *
         * @param   string  $extension  image extension: png, jpg, etc
         * @return  string  IMAGETYPE_* constant
         * @throws  ErrorException
         */
        protected function _get_imagetype($extension)
        {
                if(!defined('IMAGETYPE_WEBP')){
                    define('IMAGETYPE_WEBP', 18);
                }
                // Normalize the extension to a format
                $format = strtolower($extension);

                switch ($format)
                {
                        case 'jpg':
                        case 'jpeg':
                                $type = IMAGETYPE_JPEG;
                        break;
                        case 'gif':
                                $type = IMAGETYPE_GIF;
                        break;
                        case 'png':
                                $type = IMAGETYPE_PNG;
                        break;
                        case 'webp':
                                $type = IMAGETYPE_WEBP;
                        break;
                        default:
                                throw new ErrorException(sprintf('Installed ImageMagick does not support %s images',$extension));
                        break;
                }

                return array($format, $type);
        }

        protected function _do_interlace($scheme)
        {
            return $this->im->setInterlaceScheme($scheme);
        }
} // End Kohana_Image_Imagick
