<?php

$videos = Video::model('find', array('state' => 'pending', 'deleted' => 0))->sort(array('date_uploaded' => 1));

foreach($videos as $video){
	glue::db()->videos->update(array('_id' => $video->_id), array('$set' => array('state' => 'submitting')));
	glue::zencoder()->submit($video->_id, $video->original);
}