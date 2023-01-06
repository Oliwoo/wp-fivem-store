<?php 
    class DiscordMessageManager{

        function __construct($webhook_url = null){
            $this->discord_webhook = $webhook_url;
        }

        function send($embed){
            if($this->discord_webhook){
                $data = array("embeds" => [$embed]);
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->discord_webhook,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                    CURLOPT_HTTPHEADER => [
                        "Content-Type: application/json"
                    ]
                ]);

                $response = curl_exec($ch);
                curl_close($ch);
                return $response;
            }else{
                return 0;
            }
        }        
    }

    class EmbedMessage{
        function __construct($title, $description, $url, $color, $footer, $image, $thumb, $author, $fields){
            $this->title = $title;
            $this->description = $description;
            $this->url = $url;
            $this->color = hexdec($color);
            $this->footer = $footer;
            $this->image = $image;
            $this->thumb = $thumb;
            $this->author = $author;
            $this->fields = $fields;
        }   

        function get(){
            if($this->title!=null && $this->description != null){
                return array(
                    "title" => $this->title,
                    "description" => $this->description,
                    "url" => $this->url,
                    "color" => $this->color,
                    "footer" => $this->footer,
                    //"image" => $this->image,
                    //"thumbnail" => $this->thumb,
                    //"author" => $this->author,
                    "fields" => $this->fields
                );
            }else{
                return null;
            }
        }

    }

    class Footer{
        function __construct($text, $icon_url){
            $this->text = $text;
            $this->icon_url = $icon_url;
        }

        function get(){
            if($this->text!=null && $this->icon_url!=null){
                return array(
                    "text" => $this->text,
                    "icon_url" => $this->icon_url?$this->icon_url:get_site_icon_url()
                );
            }else{
                return null;
            }
        }
    }

    class Author{
        function __construct($name, $url){
            $this->name = $name;
            $this->url = $url;
        }

        function get(){
            if($this->name!=null){
                return array(
                    "name" => $this->name,
                    "url" => $this->url
                );
            }else{
                return null;
            }
        }
    }

    class Field{
        function __construct($name, $value, $inline){
            $this->name = $name;
            $this->value = $value;
            $this->inline = $inline?true:false;
        }

        function get(){
            if($this->name!=null && $this->value!=null){
                return array(
                    "name" => $this->name,
                    "value" => $this->value,
                    "inline" => $this->inline
                );
            }else{
                return null;
            }
        }
    }

?>