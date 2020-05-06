<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use YoutubeDl\YoutubeDl;

class ApiController extends Controller
{
    const POSSIBLE_FORMATS = ['mp3', 'mp4'];

    public function convert(Request $request)
    {
        $url = $request->get('url');
        $format = $request->get('format', 'mp3');

        if(!in_array($format, self::POSSIBLE_FORMATS))
            return new Response(['error' => true, 'message' => 'Invalid format (choose between '. implode(', ', self::POSSIBLE_FORMATS). ')'], 422);

        $success = preg_match('#(?<=v=)[a-zA-Z0-9-]+(?=&)|(?<=v\/)[^&\n]+|(?<=v=)[^&\n]+|(?<=youtu.be/)[^&\n]+#', $url, $matches);

        if(!$success)
            return new Response(['error' => true, 'message' => 'No video id specified'], 422);

        $id = $matches[0];
        $downloadFolder = storage_path('app/public') . '/'; //create symbolic link (php artisan storage:link)

        $exists = file_exists($downloadFolder.$id.".".$format);

        if(env('DOWNLOAD_MAX_LENGTH', 0) > 0 || $exists)
        {
            $dl = new YoutubeDl(['skip-download' => true]);
            $dl->setDownloadPath($downloadFolder);
        
            try	{
                $video = $dl->download($url);
        
                if($video->getDuration() > env('DOWNLOAD_MAX_LENGTH', 0) && env('DOWNLOAD_MAX_LENGTH', 0) > 0)
                    return new Response(['error' => true, 'message' => "The duration of the video is {$video->getDuration()} seconds while max video length is ".env('DOWNLOAD_MAX_LENGTH', 0)." seconds."]);
            }
            catch (Exception $ex)
            {
                return new Response(['error' => true, 'message' => $ex->getMessage()]);
            }
        }

        if(!$exists)
        {
            if($format == 'mp3')
            {
                $options = array(
                    'extract-audio' => true,
                    'audio-format' => 'mp3',
                    'audio-quality' => 0,
                    'output' => '%(id)s.%(ext)s',
                    //'ffmpeg-location' => '/usr/local/bin/ffmpeg'
                );
            }
            else
            {
                $options = array(
                    'continue' => true,
                    'format' => 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best',
                    'output' => '%(id)s.%(ext)s'
                );
            }

            $dl = new YoutubeDl($options);
            $dl->setDownloadPath($downloadFolder);
        }

        try
        {
            $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/storage/";
            if($exists)
                $file = $fullUrl.$id.".".$format;
            else
            {
                $video = $dl->download($url);
                $file = $fullUrl.$video->getFilename();
            }

            return new JsonResponse([
                'error' => false,
                'youtube_id' => $video->getId(),
                'title' => $video->getTitle(),
                'alt_title' => $video->getAltTitle(),
                'duration' => $video->getDuration(),
                'file' => $file,
                'uploaded_at' => $video->getUploadDate()
            ]);
        }
        catch (Exception $e)
        {
            return new Response(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    public function remove(Request $request, string $id)
    {
        $formats = $request->get('format', self::POSSIBLE_FORMATS);
        $removedFiles = [];

        if(!is_array($formats)) {
            $formats = [$formats];
        }

        foreach($formats as $format) {
            $localFile = $id.".".$format;
            if(Storage::disk('public')->exists($localFile)) {
                Storage::disk('public')->delete($id.".".$format);
                $removedFiles[] = $format;
            }
        }

        $resultNotRemoved = array_diff(self::POSSIBLE_FORMATS, $removedFiles);

        if(empty($removedFiles))
            $message = 'No files removed.';
        else
            $message = 'Removed files: ' . implode(', ', $removedFiles) . '.';

        if(!empty($resultNotRemoved))
            $message .= ' Not removed: ' . implode(', ', $resultNotRemoved);

        
        return new JsonResponse(['error' => false, 'message' => $message]);
    }
}