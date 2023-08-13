<?php
// info //
// author: Timo Kats
// description: scrapes PhD sites to find fitting positions.
// requirements: a settings.json file, apt-get install sendmail and a PHP installation.

// global settings //
$settings = json_decode(file_get_contents('settings.json'), true);
$log = json_decode(file_get_contents("log.json"), true);
$arrContextOptions=array(
    "ssl"=>array(
        "verify_peer"=>false,
        "verify_peer_name"=>false,
    ),
);  

// scrape the search page for adverts // 
function getHtmlContents($url)
{
    global $arrContextOptions;
    $str = file_get_contents($url, false, stream_context_create($arrContextOptions));    
    return $str; 
}

function getCountries($data)
{
    preg_match_all('/<a (.*)class="text-success">(.*)<\/a>/', $data, $matches);
    return $matches[2];
}

function getLinks($data)
{
    preg_match_all('/<a href="(.*) data-ev-cat/', $data, $matches);
    return $matches[1];
}

function getAdverts($url)
{
    global $settings;
    $countries = array();
    $links = array();
    for ($i = 1; $i <= 1500; $i++)
    { 
        $data = getHtmlContents($url . $i);
        $newCountries = getCountries($data);
        $newLinks = getLinks($data);
        for ($j = 0; $j < count($newCountries); $j++)
        {
            if (in_array($newCountries[$j], $settings["countries"]))
                array_push($links, $newLinks[$j]);
        }
    }
    return $links;
}


// scrape the advert page //
class Advert 
{
    public $url;
    public $title;
    public $deadline;
    public $score;
}

function getDeadline($data)
{
    foreach (preg_split("/((\r?\n)|(\r\n?))/", $data) as $line)
    {
        if (strlen($line) > 4 && $deadlineFound)
            return strip_tags($line);
        if (str_contains($line, 'Application deadline'))
            $deadlineFound = True;
    }
}

function getTitle($data)
{
    foreach (preg_split("/((\r?\n)|(\r\n?))/", $data) as $line)
    {
        if (str_contains($line, '<title>'))
            return explode("|", strip_tags($line))[0];
    }
}

function getScore($data)
{
    global $settings;
    $score = 0;
    foreach ($settings["keywords"] as $keyword)
    {
        foreach (preg_split("/((\r?\n)|(\r\n?))/", $data) as $line)
        {
            if (str_contains($line,  explode("/",$keyword)[0]))
            {
                $score += intval(explode("/", $keyword));
                break 1;
            }
        }
    }
    return $score;
}

function scrapeAdvert($url)
{
    global $arrContextOptions;
    $data = file_get_contents(rtrim($url,'"'), false, stream_context_create($arrContextOptions));
    $ad = new Advert();
    $ad->url = rtrim($url,'"');
    $ad->deadline = getDeadline($data);
    $ad->score = getScore($data);
    $ad->title = getTitle($data);
    return $ad;
}

// send email //
function swap(&$addA, &$addB)
{
    $tempAdd = $addA;
    $addA = $addB;
    $addB = $tempAdd; 

}

function rankAdverts($ads)
{
    for ($i = 0; $i < count($ads); $i++)
    {
        $swapped = false;
        for ($j = 0; $j < count($ads) - 1; $j++)
        {
            if($ads[$j]->score < $ads[$j+1]->score)
            {
                swap($ads[$j], $ads[$j+1]);
                $swapped = true;

            }
        }
        if(!$swapped)
            break;
    }
    return $ads;
}


function sendEmail($ads)
{
    global $settings, $log;
    // set email values
    $ads = rankAdverts($ads);
    $to = $settings["email"];
    $topic = "PhD suggestions for " . date('Y-m-d');
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: <webmaster@example.com>' . "\r\n";
    $message = "<html><body><ol>";
    // other
    $counter = 0;
    for ($i = 0; $i < count($ads); $i++)
    {
        if(!in_array($ads[$i]->url, $log))
        {
            $message .= "<li><b>" . $ads[$i]->title . "</b><br>relevance score: " . strval($ads[$i]->score) . "<br>";
            $message .= "deadline: " . $ads[$i]->deadline . "<br><a href='" . $ads[$i]->url . "'>click here for the ad</a></li>";
            $counter += 1;
            array_push($log, $ads[$i]->url);
        }
        if($counter == intval($settings["count"]))
            break 1;
    }
    $message .= '</ol></body></html>';
    $send = mail($to, $topic, $message, $headers);
    if($send)
        echo "email sent" . PHP_EOL;
    else
        echo "email not sent." . PHP_EOL;
}

// main runner function//
function main()
{
    error_reporting(E_ERROR | E_PARSE);
    global $settings, $log;
    $ads = array();
    $advertLinks = getAdverts("https://scholarshipdb.net/PhD-scholarships?page=");
    for ($i = 0; $i < count($advertLinks); $i++)
        array_push($ads, scrapeAdvert("https://scholarshipdb.net" . $advertLinks[$i]));
    sendEmail($ads);
    file_put_contents("log.json", json_encode($log, JSON_PRETTY_PRINT));
}

while(true)
{
    main();
    sleep(259200);
}
?>

