<?php

class GitHub
{
    // The GitHub API requires a user-agent
    private $userAgent = 'RandomGit (maxime_dev@outlook.com)';
    
    // Repositories with a number of stars below minStars are considered to be "not interesting"
    private $minStars = 5;
    
    // Used when doing a OAuth request to the API
    private $oAuthId;
    private $oAuthSecret;
    
    function __construct($oAuthId, $oAuthSecret)
    {
        Requests::register_autoloader();
        $this->oAuthId = $oAuthId;
        $this->oAuthSecret = $oAuthSecret;
    }
    
    public function getRandomRepo()
    {
        $randomRepoList = GitHub::getRandomRepoList();
        $randomIndex = rand(0, count($randomRepoList));
        
        return $randomRepoList[$randomIndex];
    }
    
    /* If $interestingOnly is set to true, every repository with a number of
     * stars less than $minStars will be omitted.
     * The default value of $interestingOnly is false.
     */
    public function getRandomRepoList($interestingOnly = false)
    {
        $query = Helper::randomAlphaNumString(2);
        // When interestingOnly = true, only search for random repositories with more stars than minStars (reduces the number of uninteresting repositories)
        if ($interestingOnly) {
            $query .= ' stars:>=' . $this->minStars;
        }
        return GitHub::searchRepo($query);
    }
    
    /* Returns an array of repositories matching the search query
     * Ignores repository without a readme and last updated more than a year ago
     */
    public function searchRepo($query)
    {
        $response = $this->request('https://api.github.com/search/repositories',
         array('q' => $query),
         array(),
         true
        );
        
        $rawRepoList = json_decode($response->body);
        
        $repoList = array();
        
        $now = new DateTime();
        
        foreach ($rawRepoList->items as $rawRepo) {
            try {
                
                $lastUpdated = DateTime::createFromFormat(DateTime::ISO8601, $rawRepo->updated_at);
                // Computes the elapsed time since the last update fo the repo
                $elapsedTime = $lastUpdated->diff($now);
                
                /* If the elapsed time since the last update is less than one
                 * year ago, store the repo. Else, ignore it.
                 */
                if ($elapsedTime->y < 1) {
                    
                    $readme_html = $this->getReadmeHTML($rawRepo->name, $rawRepo->owner->login);
                    
                    $repo = new Repo($rawRepo->id, $rawRepo->name, $rawRepo->description, $rawRepo->owner->login, $rawRepo->language, $readme_html);
                    array_push($repoList, $repo);
                    
                }
                
            } catch (GitHub_NotFoundException $e) {
                // Simply ignores the repository if no readme found
            }
        }
        
        return $repoList;
    }
    
    // Returns the readme (html) of a repository
    private function getReadmeHTML($repoName, $repoUser)
    {
        
        try {
            $response = $this->request('https://api.github.com/repos/' . urlencode($repoUser) . '/'. urlencode($repoName) . '/readme',
             array(),
             array('Accept' => 'application/vnd.github.v3.html'),
             true
            );
        } catch (GitHub_NotFoundException $e) {
            throw new GitHub_NotFoundException('Cannot find the readme associated with a respository.', 0);
        }
        
        return $response->body;
    }
    
    /* Does a request to the GitHub API
     * It always add the User-Agent to the headers since it is required by the API
     * string $url : the url of the request
     * array $parameters : the url parameters (no need to use urlencode)
     * array $headers : the HTTP headers of the request
     * bool $userAgent : add the User-Agent to the headers
     * bool $oAuth : add OAuth data to the url
     * Returns the response in a Requests_Response object
     */
    private function request($url, $parameters, $headers, $oAuth)
    {
        try {
            $fullUrl = $url . '?';
            
            // Appending the url parameters
            foreach ($parameters as $key => $value) {
                $fullUrl .= urlencode($key) . '=' . urlencode($value) . '&';
            }
            
            if ($oAuth) {
                // Appending the OAuth url parameters
                $fullUrl .= 'client_id=' . urlencode($this->oAuthId) .
                            '&client_secret=' . urlencode($this->oAuthSecret);
            }
            
            // Adding the User-Agent to the headers
            $headers['User-Agent'] = $this->userAgent;
            
            $response = Requests::get($fullUrl, $headers);
        
        } catch (Requests_Exception $e) {
            throw new RuntimeException('Unable to reach the GitHub API', 0, $e);
        }
        
        if ($response->status_code === 404) {
            throw new GitHub_NotFoundException('Cannot find the requested data.', 0);
        }
        
        if ($response->status_code === 403) {
            throw new GitHub_RateLimitException('Rate limit of the GitHub API is exceeded', 0);
        } else if(!$response->success) {
            throw new GitHub_Exception('The GitHub API encountered an error. Raw response body : ' . $response->body, 0);
        }
        
        return $response;
    }
}