<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/local/lib/php');
require_once "config.php";
require_once "Readability.php";
require_once "Embedly.php";
require_once "Cache/Lite.php";

$img_style = 'style="max-width: 500px;"';

$embedapi = new Embedly\Embedly(array(
    'key' => EMBEDLY_KEY,
    'user_agent' => 'Mozilla/5.0 (compatible; redditfeed/1.0)'
));

$cache = new Cache_Lite(array(
    'cacheDir' => '/tmp/',
    'lifeTime' => 60*60*24*3,
));

function get_reddit_data($reddit){
    $data = json_decode(file_get_contents("http://www.reddit.com/r/$reddit.json"),true);
    $data = $data["data"]["children"];
    return $data;
}

function imgur_content($url){
    global $img_style;
    $parts = parse_url($url);
    $host = $parts["host"];
    $path = isset($parts["path"])?$parts["path"]:"";
    if($host == "i.imgur.com"){
        return "<img $img_style src='$url'/>";
    }else if($host == "imgur.com"){
        if(strpos($path,"/a/")===0){
            return "<a href='$url'>IMGUR ALBUM</a>";
        }else if(strpos($path,"/",1) === false){
            return "<img src='http://i.imgur.com/" . substr($path,1) . ".png'/>";
        }else{
            return "UNKNOWN IMGUR URL $url";
        }
    }else if(($host == "www.quickmeme.com" || $host == "quickmeme.com") && preg_match("/^\/meme\/([^\/]+)\//",$path,$matches)){
        return "<img $img_style src='http://i.qkme.me/" . $matches[1] . ".jpg'/>";
    }else if($host == "qkme.me" && strpos($path,"/",1) === false){
        return "<img $img_style src='http://i.qkme.me/" . substr($path,1) . ".jpg'/>";
    }
    return false;
}

function oembed($url){
    global $img_style;
    global $embedapi;
    $res = $embedapi->oembed($url);

    switch($res['type']) {
        case 'photo':
            return '<img $img_style src="' . $res['url'] . '" alt="' . (isset($res['title'])?$res['title']:"") . '"/>';
        case 'rich':
        case 'video':
            return $res['html'];
        case 'link':
        case 'error':
        default:
            return false;
    }
}

function get_mime_url($url){
    try{
        $ch = curl_init();
        $header = "Content-Type: ";
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_HEADER,         true);
        curl_setopt($ch, CURLOPT_NOBODY,         true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        5000);
        $r = curl_exec($ch);

        $r = explode("\n", $r);
        foreach($r as $k=>$v){
            if(strpos($v,$header) === 0){
                return substr($v,strlen($header));
            }
        }
    }catch(Exception $e){
        return "Error ".$e;
    }

    return "???";
}

function get_page($url){
    try{
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        20000);
        curl_setopt($ch, CURLOPT_ENCODING,     "gzip");
        $r = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_errno > 0) {
            return array(true,"cURL Error ($curl_errno): $curl_error\n");
        } else {
            return array(false,$r);
        }

    }catch(Exception $e){
        return array(true,"Error ".$e);
    }
}

function get_content_nocache($url){
    if($imgur = imgur_content($url)){
        return $imgur;
    }

    if($oembed = oembed($url)){
        return $oembed;
    }


    $mime = get_mime_url($url);
    if(strpos($mime,"image/")===0){
        return "<img src='$url'/>";
    }else if(strpos($mime,"text/plain")===0){
        return file_get_contents($url);
    }else if(strpos($mime,"text/html")===0 || strpos($mime,"application/xhtml+xml")===0 || strpos($mime,"application/xml")===0){
        try{
            $r = get_page($url);
            if($r[0]){ // Error
                return $r[1];
            }
            $html = $r[1];
            if (function_exists('tidy_parse_string')) {
                    $tidy = tidy_parse_string($html, array('indent'=>true), 'UTF8');
                    $tidy->cleanRepair();
                    $html = $tidy->value;
            }
            $readability = new Readability($html, $url);
            $readability->debug = false;
            $readability->convertLinksToFootnotes = false;
            $result = $readability->init();
            if($result){
                return "<h1>". $readability->getTitle()->textContent . "</h1>" . $readability->getContent()->innerHTML;
            }else{
                return "Readability Error :S";
            }
        }catch(Exception $e){
            return "Readability exception " . $e;
        }
    }else{
        return "Undefined mime/type $mime";
    }
}

function get_content($url){
    global $cache;
    $content = $cache->get($url);
    if(!$content){
        $content = get_content_nocache($url);
        $cache->save($content,$url);
    }
    return $content;
}

function edit_common($data){
    extract($data);
    $trans = array(
        "<" => "&lt;",
        ">" => "&gt;",
        "&" => "&amp;",
    );
    if($is_self){
        $content = html_entity_decode($data["selftext_html"]);
    }else{
        $content = get_content($url);
    }

    $data["guid"] = md5($url);
    $data["description"] = $content . "<br/>
        Score: $score ($ups/$downs)<br/>
        Author: <a href='http://www.reddit.com/user/$author'>$author</a><br/>
        <a href='$url'>Link</a> - <a href='http://reddit.com$permalink'>Comments</a> ($num_comments)";
    $data["description"] = strtr($data["description"],$trans);

    $data["author"] = htmlspecialchars($author);
    $data["title"] = htmlspecialchars($title);
    return $data;
}

$subreddit = $_GET["reddit"];
$minscore = $_GET["ups"];

foreach(get_reddit_data($subreddit) as $k=>$v){
    $data = $v["data"];
    if($data["score"] > $minscore){
        $items[] = edit_common($data);
    }
}


echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:geo="http://www.w3.org/2003/01/geo/wgs84_pos#" xmlns:content="http://purl.org/rss/1.0/modules/content/">
    <channel>
    <title>Reddit feed for <?php echo $subreddit; ?></title>
        <description>Reddit feed</description>
        <link>http://reddit.com/r/<?php echo $subreddit; ?></link>
<?php foreach($items as $k=>$v): ?>
        <item>
            <title><?php echo $v["title"]; ?></title>
            <link><?php echo htmlspecialchars($v["url"]); ?></link>
            <description><?php echo $v["description"]; ?></description>
            <author><?php echo "{$v['author']}@reddit.com ({$v['author']})"?></author>
            <guid isPermaLink="false"><?php echo htmlspecialchars($v["guid"]); ?></guid>
        </item>
<?php endforeach; ?>
    </channel>
</rss>
