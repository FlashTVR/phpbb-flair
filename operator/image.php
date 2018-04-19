<?php
/**
 *
 * Profile Flair. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2018, Steve Guidetti, https://github.com/stevotvr
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace stevotvr\flair\operator;

use phpbb\filesystem\filesystem_interface;
use stevotvr\flair\exception\base;

/**
 * Profile Flair image operator.
 */
class image extends operator implements image_interface
{
	/**
	 * @var \phpbb\filesystem\filesystem_interface
	 */
	protected $filesystem;

	/**
	 * The path to the custom images.
	 *
	 * @var string
	 */
	protected $img_path;

	/**
	 * The heights in pixels associated with each image size.
	 *
	 * @var array
	 */
	protected $sizes = array(1 => 16, 2 => 28, 3 => 54);

	/**
	 * Set up the operator.
	 *
	 * @param \phpbb\filesystem\filesystem_interface $filesystem
	 * @param string                                 $img_path      The path to the custom images
	 */
	public function setup(filesystem_interface $filesystem, $img_path)
	{
		$this->filesystem = $filesystem;
		$this->img_path = $img_path;
	}

	public function is_writable()
	{
		if ($this->filesystem->is_writable($this->img_path))
		{
			return true;
		}

		if ($this->filesystem->exists($this->img_path))
		{
			$this->filesystem->chmod($this->img_path, filesystem_interface::CHMOD_ALL);
		}
		else
		{
			$this->filesystem->mkdir($this->img_path, filesystem_interface::CHMOD_ALL);
		}

		return $this->filesystem->is_writable($this->img_path);
	}

	public function can_process()
	{
		return function_exists('gd_info') || class_exists('Imagick');
	}

	public function count_image_items($image)
	{
		$sql = 'SELECT COUNT(flair_id) AS count
				FROM ' . $this->flair_table . "
				WHERE flair_type = 1
					AND flair_img = '" . $this->db->sql_escape($image) . "'";
		$result = $this->db->sql_query($sql);
		$count = (int) $this->db->sql_fetchfield('count');
		$this->db->sql_freeresult($result);

		return $count;
	}

	public function get_images()
	{
		$images = array();

		foreach (glob($this->img_path . '*-x1.{gif,png,jpg,jpeg,GIF,PNG,JPG,JPEG}', GLOB_BRACE) as $file)
		{
			$ext = substr($file, strrpos($file, '.'));
			$name = substr($file, 0, strrpos($file, '-x1.'));

			if (!$this->filesystem->exists(array($name . '-x2' . $ext, $name . '-x3' . $ext)))
			{
				continue;
			}

			$images[] = basename($name) . $ext;
		}

		return $images;
	}

	public function get_used_images()
	{
		$images = array();

		$sql = 'SELECT flair_img
				FROM ' . $this->flair_table . '
				WHERE flair_type = 1';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$images[$row['flair_img']] = true;
		}
		$this->db->sql_freeresult($result);

		return array_keys($images);
	}

	public function add_image($name, $file)
	{
		$ext = substr($name, strrpos($name, '.'));
		$name = substr($name, 0, strrpos($name, '.'));

		if (class_exists('Imagick'))
		{
			$this->create_images_imagick($name, $ext, $file);
		}
		elseif (function_exists('gd_info'))
		{
			$this->create_images_gd($name, $ext, $file);
		}
	}

	public function delete_image($name)
	{
		$ext = substr($name, strrpos($name, '.'));
		$name = substr($name, 0, strrpos($name, '.'));
		$this->filesystem->remove(glob($this->img_path . $name . '-x[123]' . $ext));
	}

	/**
	 * Create a new image set using the Imagick library.
	 *
	 * @param string $name The base name of the output files without extension
	 * @param string $ext  The extension of the output files
	 * @param string $file The path to the source file
	 *
	 * @throws \stevotvr\flair\exception\base
	 */
	protected function create_images_imagick($name, $ext, $file)
	{
		try
		{
			$image = new \Imagick($file);

			$src_width = $image->getImageWidth();
			$src_height = $image->getImageHeight();

			$dest_path = realpath($this->img_path) . DIRECTORY_SEPARATOR;

			foreach ($this->sizes as $size => $height)
			{
				$width = (int) ($src_width * ($height / $src_height));

				$scaled = clone $image;
				if ($scaled->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1))
				{
					$scaled->writeImage($dest_path . $name . '-x' . $size . $ext);
				}

				$scaled->clear();
			}

			$image->clear();
		}
		catch (\ImagickException $e)
		{
			throw new base('EXCEPTION_IMG_PROCESSING');
		}
	}

	/**
	 * Create a new image set using the GD library.
	 *
	 * @param string $name The base name of the output files without extension
	 * @param string $ext  The extension of the output files
	 * @param string $file The path to the source file
	 *
	 * @throws \stevotvr\flair\exception\base
	 */
	protected function create_images_gd($name, $ext, $file)
	{
		$type = null;
		switch (strtolower($ext))
		{
			case '.gif':
				$type = 'gif';
			break;
			case '.png':
				$type = 'png';
			break;
			case '.jpg':
			case '.jpeg':
				$type = 'jpeg';
			break;
			default:
				throw new base('EXCEPTION_IMG_PROCESSING');
		}

		$image = call_user_func('imagecreatefrom' . $type, $file);

		if (!$image)
		{
			throw new base('EXCEPTION_IMG_PROCESSING');
		}

		$src_width = imagesx($image);
		$src_height = imagesy($image);

		foreach ($this->sizes as $size => $height)
		{
			$width = (int) ($src_width * ($height / $src_height));

			$scaled = imagecreatetruecolor($width, $height);

			if($type === "gif" || $type === "png")
			{
				imagecolortransparent($scaled, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
				imagealphablending($scaled, false);
				imagesavealpha($scaled, true);
			}

			if (imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, $src_width, $src_height))
			{
				$dest = $this->img_path . $name . '-x' . $size . $ext;
				call_user_func('image' . $type, $scaled, $dest);
			}

			imagedestroy($scaled);
		}

		imagedestroy($image);
	}
}
