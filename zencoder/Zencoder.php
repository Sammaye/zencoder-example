<?php
require_once('Services/Zencoder.php');

class Zencoder extends GApplicationComponent{
	public $api_key;
	public $model;

	function init(){
		$this->model = new Services_Zencoder($this->api_key);
	}

	function submit($video_id, $file_path){
		try {
			$job = $this->model->jobs->create('
				{
				  "input": "'.$file_path.'",
				  "private": true,
				  "output": [
				    {
				      "label": "mp4",
				      "base_url": "bucxket",
				      "format": "mp4",
				      "thumbnails": {
				        "number": 1,
				        "aspect_mode": "crop",
				        "width": 800,
				        "height": 600,
				        "base_url": "bucket",
				        "prefix": "thmb_'.new MongoId().'",
				        "public": 1,
				        "rrs": true
				      },
				      "public": 1,
				      "rrs": true,
				      "notifications": [
				        {
				          "url": "out_url",
				          "format": "json"
				        }
				      ]
				    },
				    {
				      "label": "ogg",
				      "base_url": "bucketr",
				      "format": "ogv",
				      "thumbnails": {
				        "number": 1,
				        "width": 800,
				        "height": 600,
				        "base_url": "bucket",
				        "prefix": "thmb_'.new MongoId().'",
				        "public": 1,
				        "rrs": true
				      },
				      "public": 1,
				      "rrs": true,
				      "notifications": [
				        {
				          "url": "out_url",
				          "format": "json"
				        }
				      ]
				    }
				  ]
				}
			');

			if($job->id){
				glue::db()->videos->update(array('_id' => $video_id), array('$set' => array('state' => 'transcoding')));
			}else{
				glue::db()->videos->update(array('_id' => $video_id), array('$set' => array('state' => 'pending')));
			}
			return $job->id;
		} catch (Services_Zencoder_Exception $e) {
			glue::db()->videos->update(array('_id' => $video_id), array('$set' => array('state' => 'pending')));
			return false;
		}
	}
}