<?php
/**
 * @category     Kurufootwear
 * @package      Kurufootwear\Framework
 * @author       Jim McGowen <jim@kurufootwear.com>
 * @copyright    Copyright (c) 2020 KURU Footwear. All rights reserved.
 */

namespace Kurufootwear\Rma\Helper;

use Kurufootwear\Rma\Model\Source\CustomField\Type;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Image;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\ImageProcessorInterface;
use Magento\Framework\Filesystem;
use Symfony\Component\Filesystem\Exception\InvalidArgumentException as FileException;
use Magento\Framework\Image\Factory as ImageFactory;

/**
 * Class RmaImage
 * @package Kurufootwear\Rma\Helper
 */
class RmaImageHelper extends AbstractHelper
{
    /** @var ImageContentInterface */
    protected $imageContent;
    
    /** @var ImageProcessorInterface */
    protected $imageProcessor;
    
    /** @var Filesystem */
    protected $filesystem;
    
    /** @var ImageFactory */
    protected $imageFactory;
    
    /**
     * RmaImage constructor.
     *
     * @param Context $context
     * @param ImageContentInterface $imageContent
     * @param ImageProcessorInterface $imageProcessor
     * @param Filesystem $filesystem
     * @param ImageFactory $imageFactory
     */
    public function __construct(
        Context $context,
        ImageContentInterface $imageContent,
        ImageProcessorInterface $imageProcessor,
        Filesystem $filesystem,
        ImageFactory $imageFactory
    ) {
        $this->imageContent = $imageContent;
        $this->imageProcessor = $imageProcessor;
        $this->filesystem = $filesystem;
        $this->imageFactory = $imageFactory;
        
        parent::__construct($context);
    }
    
    /**
     * Validates and saves the warranty image.
     * 
     * @param $orderId
     * @param $imageId
     * @param $image
     *
     * @return string
     * @throws \Magento\Framework\Exception\InputException
     */
    public function processWarrantyImage($orderId, $imageId, $image)
    {
        // Validate the image (throws FileException if invalid)
        $image = $this->validateImage($image);
        
        // Rename warranty images with imageIndex and rmaId
        $filename = sprintf(
            '%s_%s_%s',
            $orderId,
            $imageId,
            time()
        );
        
        $pathPrefix = 'warranty';
        
        $imageContent = $this->imageContent->setName($filename)
            ->setType($image['contentType'])
            ->setBase64EncodedData($image['encodedContent']);
        
        $unprefixedPath = $this->imageProcessor->processImageContent(
            $pathPrefix,
            $imageContent
        );
        $prefixedPath = $pathPrefix . $unprefixedPath;
        
        $mediaReader = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $absolutePath = $mediaReader->getAbsolutePath($prefixedPath);
        
        // Resize and compress the image
        $image = $this->getImage($absolutePath);
        $image = $this->constrainImageMaxDimension($image, 800);
        $image->save();
        
        return $prefixedPath;
    }
    
    /**
     * Validates an uploaded image.
     *
     * Always throws an FileException for invalid images.
     *
     * @param $image
     *
     * @return array Image data
     * @throws FileException
     */
    public function validateImage($image)
    {
        $imageUncompressed = preg_split("/[\,,\;,\:]+/", $image);
        
        if (count($imageUncompressed) !== 4) {
            // Invalid image data
            throw new FileException(__('Invalid image data.'));
        }
        
        if (!is_array($imageUncompressed)) {
            throw new FileException(
                __(
                    'One or more of the image files you selected are invalid. Please try again. If the problem persists feel free to reach out to a guru who will be happy to help.',
                    round(Type::IMAGE_MAX_SIZE / 1000000)
                )
            );
        }
        
        $image = [
            'compressedContent' => $imageUncompressed[0],
            'contentType' => $imageUncompressed[1],
            'encoding' => $imageUncompressed[2],
            'encodedContent' => $imageUncompressed[3]
        ];
        
        if ($image['encoding'] !== 'base64') {
            throw new FileException(__("One or more of the image files you selected has an invalid image encoding."));
        }
        
        if (strlen($image['encodedContent']) > Type::IMAGE_MAX_SIZE) {
            throw new FileException(
                __(
                    'One or more of the image files you selected is too large. Please select files that are less than %1 MB in size.',
                    round(Type::IMAGE_MAX_SIZE / 1000000)
                )
            );
        }
        
        return $image;
    }
    
    /**
     * Generic function for retrieving and existing image.
     *
     * @param $imagePath
     *
     * @return Image
     */
    protected function getImage($imagePath)
    {
        if (empty($image)) {
            $image = $this->imageFactory->create($imagePath);
            $image->keepAspectRatio(true);
            $image->keepFrame(false);
            $image->keepTransparency(true);
        }
        
        return $image;
    }
    
    /**
     * Resize image by the dimension which is larger
     *
     * @param Image $image
     * @param int $maxDimension
     *
     * @return Image
     */
    protected function constrainImageMaxDimension(Image $image, int $maxDimension)
    {
        $height = $image->getOriginalHeight();
        $width = $image->getOriginalWidth();
        
        if ($height < $maxDimension && $width < $maxDimension) {
            return $image;
        }
        
        if ($height > $width) {
            $image->resize(null, $maxDimension);
        } else {
            $image->resize($maxDimension);
        }
        
        return $image;
    }
}
