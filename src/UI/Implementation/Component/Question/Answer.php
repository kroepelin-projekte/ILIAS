<?php

namespace ILIAS\UI\Implementation\Component\Question;

use ILIAS\UI\Implementation\Component\Input\Field\Textarea;

class Answer
{
    protected int $id;
    protected string $answer_text;
    protected float $points;
    protected ?string $feedback;
    protected ?string $image_url;
    protected bool $checked;
    protected bool $correct;
    
    /**
     * @param int         $id
     * @param string      $answer_text
     * @param float       $points
     * @param string|null $feedback
     * @param string|null $image_url
     * @param bool        $checked
     * @param bool        $correct
     */
    public function __construct(
        int $id,
        string $answer_text,
        float $points = 0,
        ?string $feedback = null,
        ?string $image_url = null,
        bool $checked = false,
        bool $correct = false
    ) {
        $this->id = $id;
        $this->answer_text = $answer_text;
        $this->points = $points;
        $this->feedback = $feedback;
        $this->image_url = $image_url;
        $this->checked = $checked;
        $this->correct = $correct;
    }
    
    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }
    
    /**
     * @return string
     */
    public function getAnswerText() : string
    {
        return $this->answer_text;
    }
    
    /**
     * @return string|null
     */
    public function getImageUrl() : ?string
    {
        return $this->image_url;
    }
    
    /**
     * @return bool
     */
    public function isChecked() : bool
    {
        return $this->checked;
    }
    
    /**
     * @param bool $checked
     */
    public function setChecked(bool $checked) : void
    {
        $this->checked = $checked;
    }
    
    /**
     * @return bool
     */
    public function isCorrect() : bool
    {
        return $this->correct;
    }
    
    public function setCorrect() : void
    {
        if (!$this->points) {
            $this->correct = true;
        }
    }
    
    /**
     * @return float
     */
    public function getPoints() : float
    {
        return $this->points;
    }
    
    /**
     * @return Textarea|null
     */
    public function getFeedback() : ?string
    {
        return $this->feedback;
    }
    
    public function getJSONEncode() {
        return json_encode(get_object_vars($this));
    }
}