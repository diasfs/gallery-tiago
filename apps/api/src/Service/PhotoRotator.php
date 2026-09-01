<?php

namespace App\Service;

use App\Entity\Face;
use App\Entity\Photo;
use App\Support\ImageRotation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

final class PhotoRotator
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly AvifConverter $converter,
        private readonly EntityManagerInterface $em,
        private readonly string $vipsBinary = 'vips',
    ) {
    }

    public function rotate(Photo $photo, int $degrees = 90): void
    {
        $degrees = ((int) (360 + ($degrees % 360))) % 360;
        if (!\in_array($degrees, [90, 180, 270], true)) {
            throw new \InvalidArgumentException('degrees must be 90, 180, or 270.');
        }

        $originalRelative = $photo->getOriginalPath();
        $avifRelative = $photo->getAvifPath();
        if ((null === $originalRelative || '' === $originalRelative) && (null === $avifRelative || '' === $avifRelative)) {
            throw new \RuntimeException('Photo has no image to rotate.');
        }

        $width = $photo->getWidth();
        $height = $photo->getHeight();
        if (null === $width || null === $height || $width <= 0 || $height <= 0) {
            $probe = $originalRelative ?: $avifRelative;
            [$width, $height] = $this->converter->readDimensions($this->storage->absolutePath((string) $probe));
            $photo->setWidth($width);
            $photo->setHeight($height);
        }

        $vipsAngle = ImageRotation::vipsAngle($degrees);

        if (null !== $originalRelative && '' !== $originalRelative) {
            $originalAbsolute = $this->storage->absolutePath($originalRelative);
            if (is_file($originalAbsolute)) {
                $this->rotateFileInPlace($originalAbsolute, $vipsAngle);
            }
        }

        if (null !== $avifRelative && '' !== $avifRelative) {
            $avifAbsolute = $this->storage->absolutePath($avifRelative);
            if (!is_file($avifAbsolute)) {
                throw new \RuntimeException('Photo AVIF master not found.');
            }

            $this->rotateFileInPlace($avifAbsolute, $vipsAngle);

            $thumbRelativeBySize = [];
            $thumbAbsoluteBySize = [];
            foreach (AvifConverter::THUMBNAIL_SIZES as $size) {
                $relative = $this->storage->thumbPath((string) $photo->getId(), $size);
                $thumbRelativeBySize[(string) $size] = $relative;
                $thumbAbsoluteBySize[$size] = $this->storage->absolutePath($relative);
            }
            $this->converter->generateThumbnails($avifAbsolute, $thumbAbsoluteBySize);
            $photo->setThumbPaths($thumbRelativeBySize);
        }

        if (90 === $degrees || 270 === $degrees) {
            $photo->setWidth($height);
            $photo->setHeight($width);
        }

        foreach ($photo->getFaces() as $face) {
            $this->rotateFace($face, $width, $height, $degrees, $vipsAngle);
        }

        $this->em->flush();
    }

    private function rotateFace(Face $face, int $imageWidth, int $imageHeight, int $degrees, string $vipsAngle): void
    {
        $x = $face->getX();
        $y = $face->getY();
        $width = $face->getWidth();
        $height = $face->getHeight();
        if (null !== $x && null !== $y && null !== $width && null !== $height && $width > 0.0 && $height > 0.0) {
            $box = ImageRotation::transformBox($x, $y, $width, $height, $imageWidth, $imageHeight, $degrees);
            $face->setX($box['x']);
            $face->setY($box['y']);
            $face->setWidth($box['width']);
            $face->setHeight($box['height']);
        }

        $cropRelative = $face->getCropPath();
        if (null !== $cropRelative && '' !== $cropRelative) {
            $cropAbsolute = $this->storage->absolutePath($cropRelative);
            if (is_file($cropAbsolute)) {
                $this->rotateFileInPlace($cropAbsolute, $vipsAngle);
            }
        }

        if ($face->hasEmbedding()) {
            $face->setEmbedding(null);
        }
    }

    private function rotateFileInPlace(string $absolutePath, string $vipsAngle): void
    {
        if (!is_file($absolutePath)) {
            throw new \RuntimeException(\sprintf('Image "%s" does not exist.', $absolutePath));
        }

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);
        $temporary = \sprintf(
            '%s/.rotate-%s.%s',
            \dirname($absolutePath),
            bin2hex(random_bytes(4)),
            '' !== $extension ? $extension : 'tmp',
        );
        @unlink($temporary);

        $process = new Process([$this->vipsBinary, 'rot', $absolutePath, $temporary, $vipsAngle]);
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful()) {
            @unlink($temporary);
            throw new \RuntimeException(\sprintf(
                'Image rotation failed: %s',
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }

        if (!rename($temporary, $absolutePath)) {
            @unlink($temporary);
            throw new \RuntimeException(\sprintf('Unable to replace rotated image "%s".', $absolutePath));
        }
    }
}
