<?php

$videos = Video::model('find', array('state' => 'pending', 'deleted' => 0))->sort(array('date_uploaded' => 1));

foreach($videos as $video){
	glue::db()->videos->update(array('_id' => $video->_id), array('$set' => array('state' => 'submitting')));
	glue::zencoder()->submit($video->_id, $video->original);
}




	function action_process_encoding(){

		//echo "here"; exit();

		$notification = glue::zencoder()->model->notifications->parseIncoming();
		$thumbnail = '';
		$failed = true;

		// Check output/job state
		if($notification->output->state == "finished") {
			// Get thumbnails
			for($i=0,$t_size = sizeof($notification->output->thumbnails);$i<$t_size;$i++){
				$thumb_row = $notification->output->thumbnails[$i];
				for($j=0,$r_size = sizeof($thumb_row['images']); $j<$r_size;$j++){
					$thumb = $thumb_row['images'][$j];
					$thumbnail = $thumb['url'];
				}
			}
		}

		if($notification->output->label == 'ogg'){
			$job = array(
				'ogg_state' => $notification->output->state == "finished" ? 'finished' : 'failed',
				'ogg_url' => $notification->output->url,
				'thumbnail' => $thumbnail,
				'duration_ts' => $notification->output->duration_in_ms
			);
		}elseif($notification->output->label == 'mp4'){
			$job = array(
				'mp4_state' => $notification->output->state == "finished" ? 'finished' : 'failed',
				'mp4_url' => $notification->output->url,
				'thumbnail' => $thumbnail,
				'duration_ts' => $notification->output->duration_in_ms
			);
		}
		glue::db()->zencoder_job->update(array('job_id' => $notification->job->id), array('$set' => $job), array('upsert' => true));
		$job = glue::db()->zencoder_job->findOne(array('job_id' => $notification->job->id));

		if(!$job){
			// Somethings gone wrong!
			return;
		}

		$bytes  = null;
		if($job['ogg_state'] == 'finished' && $job['mp4_state'] == 'finished'){
			$failed = false;
			// Lets write the image in two different formats to the video_images collection foir caching
			$file_name = pathinfo($job['thumbnail'], PATHINFO_BASENAME);
			if(strlen($file_name) > 0){
				$obj = glue::s3_upload()->get_file($file_name);
				$bytes = $obj->body;
			}
		}

		if(($job['ogg_state'] == 'finished' || $job['ogg_state'] == 'failed') && ($job['mp4_state'] == 'finished' || $job['mp4_state'] == 'failed')){
			$videos = Video::model('find', array('job_id' => $notification->job->id));
			foreach($videos as $k => $video){
				$video->setScenario('zencoder');

				// Per video lets do the stuff needed
				if(!$failed){

					$video->duration_ts = $job['duration_ts'];
					$video->ogg = $job['ogg_url'];
					$video->mp4 = $job['mp4_url'];
					$video->image = $job['thumbnail'];
					$video->state = 'finished';

					if($bytes)
						$video->image_src = new MongoBinData($bytes);

					$user = User::model('findOne', array('_id' => $video->user_id));
					Stream::videoUpload($user->_id, $video->_id);
					if($user->should_autoshare('upload')){
						AutoPublishStream::add_to_qeue(AutoPublishStream::UPLOAD, $user->_id, $video->_id);
					}
					$user->uploads = $user->uploads+1;
					$user->save();

					$listing = 1;
					if($video->listing == 'u'){
						$listing = 2;
					}elseif($video->listing == 'n'){
						$listing = 3;
					}

					glue::mysql()->query("INSERT INTO documents (_id, uid, listing, title, description, category, tags, author_name, duration, views, rating, type, adult, date_uploaded)
								VALUES (:_id, :uid, :listing, :title, :description, :cat, :tags, :author_name, :duration_ts, :views, :rating, :type, :adult, now())", array(
						":_id" => strval($video->_id),
						":uid" => strval($video->user_id),
						":listing" => $listing,
						":title" => $video->title,
						":description" => $video->description,
						":cat" => $video->category,
						":tags" => $video->string_tags,
						":duration_ts" => $video->duration_ts,
						":rating" => $video->likes - $video->dislikes,
						":views" => $video->views,
						":type" => "video",
						":adult" => $video->adult_content,
						":author_name" => $user->username,
					));

					if($listing == 1){
						glue::sitemap()->add_to_sitemap(glue::url()->create('/video/watch', array('id' => $video->_id)), 'hourly', '1.0');
					}
				}else{
					$video->state = 'failed';
				}
				$video->save();
			}
		}
	}