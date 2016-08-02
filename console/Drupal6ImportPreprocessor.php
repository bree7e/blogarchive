<?php
/**
 * blogarchive:d6_preprocess_import command
 * Used to preprocess CSV file to import blog posts exported from Drupal 6 content
 */

namespace Graker\BlogArchive\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Input;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class Drupal6ImportPreprocessor extends Command {

  /**
   * @var string The console command name.
   */
  protected $name = 'blogarchive:d6_preprocess_import';

  /**
   * @var string The console command description.
   */
  protected $description = 'Preprocess CSV file to import blog posts exported from Drupal 6 nodes.';
  
  
  /**
   * @var int position of id column
   */
  protected $id_index = 0;
  
  
  /**
   * @var int position of title column
   */
  protected $title_index = 0;
  

  /**
   * @var int position of content column
   */
  protected $content_index = 0;


  /**
   * @var int position of teaser column
   */
  protected $teaser_index = 0;


  /**
   * @var int position of link column
   */
  protected $link_index = 0;


  /**
   * @var int position of categories column
   */
  protected $categories_index = 0;
  
  
  /**
   * @var int position of created column
   */
  protected $created_index = 0;


  /**
   * @var string path where to place new file links
   */
  protected $file_links = '';
  
  
  /**
   * @var string domain to replace with internal links (if there are external links)
   */
  protected $domain = '';
  
  
  /**
   * @var string text for disqus comments (if set)
   */
  protected $discus_text = '';
  
  
  /**
   * @var string file name with disqus import-to-be
   */
  protected $discus_file = '';


  /**
   * @var array of 'code tag' => 'language-lng' (or '' if no specific language)
   */
  protected $code_tags = [
    'code' => '',
    'javascript' => 'lang-js',
    'cpp' => 'lang-cpp',
    'php' => 'lang-php',
    'drupal6' => 'lang-php',
    'qt' => 'lang-cpp',
    'bash' => 'lang-bsh',
  ];


  /**
   * Execute the console command.
   * @return void
   */
  public function fire()
  {
    $filename = $this->argument('input_file');
    $this->output->writeln("Processing file $filename");
    
    if ($this->option('disqus-comments')) {
      $this->readDisqusExport();
    }

    $csv = Reader::createFromPath($filename);
    if (!$csv) {
      $this->error('Can not parse CSV file');
      return;
    }
    $first_row = $csv->fetchOne();
    // set up column positions
    if (!$this->findColumn($first_row, 'nid', $this->id_index)) {
      return;
    }
    if (!$this->findColumn($first_row, 'title', $this->title_index)) {
      return;
    }
    if (!$this->findColumn($first_row, 'content', $this->content_index)) {
      return;
    }
    if (!$this->findColumn($first_row, 'teaser', $this->teaser_index)) {
      return;
    }
    if (!$this->findColumn($first_row, 'link', $this->link_index)) {
      return;
    }
    if (!$this->findColumn($first_row, 'categories', $this->categories_index)) {
      return;
    }
    if (!$this->findColumn($first_row, 'created', $this->created_index)) {
      return;
    }

    //process rows
    $this->file_links = $this->option('files');
    $this->domain = $this->option('replace-external');
    $rows = $csv->fetchAll();
    array_shift($rows); // remove header
    $count = count($rows);
    $this->info("CSV file is parsed. It has $count rows. Processing them now...");
    foreach ($rows as &$row) {
      $this->processRow($row);
    }
    
    // post-process rows
    $this->info('Starting rows post-process');
    foreach ($rows as &$row) {
      $this->postProcess($row, $rows);
    }

    //save updated rows
    $output_file = $this->argument('output_file');
    $this->info('Processing finished. Saving...');
    $writer = Writer::createFromFileObject(new SplTempFileObject);
    $writer->insertOne($first_row);
    $writer->insertAll($rows);
    if (!file_put_contents($output_file, $writer->__toString())) {
      $this->error("Failed to write to $output_file");
    } else {
      $this->output->writeln("Processed CSV is written to $output_file");
    }
    
    //save disqus changes
    if ($this->discus_file) {
      $this->saveDisqusExport();
    }
  }


  /**
   *
   * Processes one row of CSV data
   *
   * @param array $row the row to be processed and changed
   */
  protected function processRow(&$row) {
    $this->checkTeaser($row);
    $this->getLink($row);
    $this->processCategories($row);
    $this->processHTML($row);
    if ($this->discus_file) {
      $this->processDisqusRow($row);
    }
  }
  
  
  /**
   *
   * Post-processing for row
   * This happens when all rows are processed and information is gathered
   *
   * @param $row
   * @param $rows array of processed rows to reference
   */
  protected function postProcess(&$row, $rows) {
    // check row slug for uniqueness
    $slug = $original_link = $row[$this->link_index];
    $i = 1;
    while (!$this->isUniqueSlug($slug, $rows, $row[$this->id_index])) {
      $slug = $original_link . "-$i";
      $i ++;
    }
    if ($slug != $original_link) {
      $row[$this->link_index] = $slug;
      $this->output->writeln("Fixed non-unique slug for row " . $row[$this->title_index]);
    }
  }
  
  
  /**
   *
   * Check whether $slug given is unique through all the $rows
   *
   * @param $slug
   * @param $rows
   * @param int $id - id of row being checked (to avoid comparing to itself)
   * @return bool
   */
  protected function isUniqueSlug($slug, $rows, $id) {
    foreach ($rows as $row) {
      if ($row[$this->id_index] == $id) {
        continue;
      }
      if ($row[$this->link_index] == $slug) {
        return false;
      }
    }
    return true;
  }


  /**
   *
   * Checks if this row has teaser equal to content
   * Remove the teaser in this case (i.e. no excerpt needed)
   *
   * @param array $row the row to be processed and changed
   */
  protected function checkTeaser(&$row) {
    if ($row[$this->content_index] == $row[$this->teaser_index]) {
      $row[$this->teaser_index] = '';
      $this->output->writeln("Removing teaser for node $row[0]");
    }
  }


  /**
   *
   * Parses node's slug from the link field given
   * In D6 Views link comes as an anchor tag with full URL in href attribute
   * For slug, we should get the last part of the URL, after last / symbol
   *
   * @param array $row the row to be processed and changed
   */
  protected function getLink(&$row) {
    $start_pos = strpos($row[$this->link_index], '"');
    if (!$start_pos) {
      $this->error("Error parsing link for node $row[0]");
      return ;
    }
    $str = substr($row[$this->link_index], $start_pos+1);
    $close_pos = strpos($str, '"');
    $str = substr($str, 0, $close_pos);
    if ($this->option('report-redirects')) {
      $this->checkRedirectReport($str, $row[$this->title_index]);
    }
    $parts = explode('/', $str);
    $link = array_pop($parts);
    // October has a restriction: slug must be at least 3 chars and no longer than 64 chars
    $link = $this->checkLinkLength($link, $row);
    $row[$this->link_index] = $link;
  }
  
  
  /**
   *
   * Checks if the link is not in /news/<year>/<month>/<day>/<slug> pattern
   *
   * @param string $link
   * @param string $title title of content
   */
  protected function checkRedirectReport($link, $title) {
    //for now we just check for /news and report if it isn't the case
    if (strpos($link, 'news/') != 1) {
      $this->warn("Possible redirect found at $link for content $title");
    }
  }
  
  
  /**
   *
   * Checks if $link meets October's requirement to be at least 3 chars and no longer than 64 chars
   * If not, tries to propose a new link:
   *  - from a title
   *  - by prepending 'id-' to the link
   *
   * @param string $link
   * @param array $row
   * @return string
   */
  protected function checkLinkLength($link, $row) {
    $length = strlen($link);
    if ((3 <= $length) && ($length <= 64)) {
      return $link;
    }
    $title = $row[$this->title_index];
    $this->output->writeln("Updating link length for title=" . $title);
    
    //try to use transliterated title
    if ($length < 3) {
      // update short link
      $string = transliterator_transliterate("Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; [:Punctuation:] Remove; Lower();", $title);
      $string = preg_replace('/[-\s]+/', '-', $string);
      $string = trim($string, '-');
      if (strlen($string) >= 3) {
        $this->output->writeln("Link for title=$title is replaced with transliterated title");
        return $string;
      } else {
        $this->output->writeln("Link for title=$title is prepended with id-");
        return 'id-' . $link;
      }
    } else {
      // update long link - cut to 61 symbol to have room for unique processing later)
      $string = substr($link, 0, 61);
      return $string;
    }
  }


  /**
   *
   * Replaces standard D6 ", " delimiter in categories with "|"
   * Processes short (less than 3 symbols) category names to meet slug requirements
   *
   * @param array $row the row to be processed and changed
   */
  protected function processCategories(&$row) {
    $row[$this->categories_index] = str_replace(', ', '|', $row[$this->categories_index]);
    $categories = explode('|', $row[$this->categories_index]);
    if (empty($categories)) return ;
    foreach ($categories as &$category) {
      if (mb_strlen($category) < 3) {
        // add suffix to short category names
        $category .= '-tag';
      }
    }
    $row[$this->categories_index] = implode('|', $categories);
  }
  
  
  /**
   *
   * Evaluates url to be for this row (/news/y/m/d/slug)
   * and replaces /node/id with it in disqus text
   *
   * @param array $row the row to be processed
   */
  protected function processDisqusRow($row) {
    $created = new Carbon($row[$this->created_index]);
    $url = '/news';
    $url .= '/' . date('Y', $created->getTimestamp());
    $url .= '/' . date('m', $created->getTimestamp());
    $url .= '/' . date('d', $created->getTimestamp());
    $url .= '/' . $row[$this->link_index];
    $url = '<link>' . $url . '</link>';
    
    $old_url = '<link>http://graker.ru/node/' . $row[$this->id_index] . '</link>';
    
    $this->discus_text = str_replace($old_url, $url, $this->discus_text);
  }


  /**
   *
   * Process all HTML changes (teaser and content)
   *
   * @param $row
   */
  protected function processHTML(&$row) {
    if ($row[$this->content_index]) {
      $this->processHTMLString($row[$this->content_index], $row[$this->title_index]);
    }
    if ($row[$this->teaser_index]) {
      $this->processHTMLString($row[$this->teaser_index], $row[$this->title_index]);
    }
  }
  
  
  /**
   *
   * Creates dom object for $html given and launches each enabled process for this dom
   * Then processed dom is dumped back to $html given
   *
   * @param string $html
   * @param string $title - title of the row to report back of some changes
   */
  protected function processHTMLString(&$html, $title) {
    $dom = $this->createDOM($html);

    //processing DOM
    if ($this->domain) {
      $this->replaceExternalLinks($dom);
    }
    if ($this->file_links) {
      $this->processFileLinks($dom);
    }
    if ($this->option('lightbox-to-magnific')) {
      $this->lightboxToMagnific($dom);
    }
    if ($this->option('magnify-orphan-previews')) {
      $this->magnifyOrphanPreviews($dom);
    }
    if ($this->option('code-to-prettify')) {
      if ($this->replaceCodeWithPrettify($dom)) {
        $this->info("Fixed code block for post $title");
      }
    }
    if ($this->option('report-gallery-links')) {
      if ($this->hasGalleryLinks($dom)) {
        $this->warn("Found gallery links in content $title");
      }
    }
    if ($this->option('object-p')) {
      if ($this->replaceObjectP($dom)) {
        $this->warn("Fixed object inside paragraph in content $title");
      }
    }

    $html = $this->dumpDOM($dom);
  }


  /**
   *
   * Creates DOMDocument for html given and returns it to use in further changes
   *
   * @param $html
   * @return \DOMDocument
   */
  protected function createDOM($html) {
    //wrap html
    $html = '<div id="post-import-wrapper">' . $html . '</div>';
    //disable errors for broken html
    libxml_use_internal_errors(true);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    return $dom;
  }


  /**
   *
   * Dumps processed DOMDocument back to string representation and returns it
   *
   * @param \DOMDocument $dom
   * @return string
   */
  protected function dumpDOM($dom) {
    $post_wrapper = $dom->getElementById('post-import-wrapper');
    $html = $dom->saveHTML($post_wrapper);
    //remove wrapper
    $html = str_replace('<div id="post-import-wrapper">', '', $html);
    $html = mb_substr($html, 0, mb_strlen($html)-6);
    return $html;
  }
  
  
  /**
   *
   * Replaces http://domain/ with / to fix external links which should really be internal
   *
   * @param \DOMDocument $dom
   */
  protected function replaceExternalLinks($dom) {
    $domain = 'http://' . $this->domain . '/';
    // rewrite links
    foreach ($dom->getElementsByTagName('a') as $tag) {
      $href = $tag->getAttribute('href');
      if (substr($href, 0, strlen($domain)) === $domain) {
        $this->output->writeln("Replacing " . $href . " external link");
        $href = str_replace($domain, '/', $href);
        $tag->setAttribute('href', $href);
      }
    }
    
    // rewrite images
    foreach ($dom->getElementsByTagName('img') as $tag) {
      $src = $tag->getAttribute('src');
      if (substr($src, 0, strlen($domain)) === $domain) {
        $this->output->writeln("Replacing " . $src . " external image");
        $src = str_replace($domain, '/', $src);
        $tag->setAttribute('src', $src);
      }
    }
  }
  
  
  /**
   *
   * Returns true if there are links to galleries in $dom
   *
   * @param $dom
   * @return bool
   */
  protected function hasGalleryLinks($dom) {
    foreach ($dom->getElementsByTagName('a') as $tag) {
      $href = $tag->getAttribute('href');
      if (strstr($href, 'image/image_galleries')) {
        return TRUE;
      }
    }
    return FALSE;
  }


  /**
   *
   * Replaces sites/default/files with a path in $this->file_links for each anchor's href and img's src
   *
   * @param \DOMDocument $dom
   */
  protected function processFileLinks($dom) {
    $old_files = '/sites/default/files';

    //rewrite links
    foreach ($dom->getElementsByTagName('a') as $tag) {
      $href = $tag->getAttribute('href');
      if (substr($href, 0, strlen($old_files)) === $old_files) {
        $this->output->writeln("Replacing " . $href . " link");
        $href = str_replace($old_files, $this->file_links, $href);
        $tag->setAttribute('href', $href);
      }
    }

    //rewrite images
    foreach ($dom->getElementsByTagName('img') as $tag) {
      $src = $tag->getAttribute('src');
      if (substr($src, 0, strlen($old_files)) === $old_files) {
        $this->output->writeln("Replacing " . $src . " image");
        $src = str_replace($old_files, $this->file_links, $src);
        $tag->setAttribute('src', $src);
      }
    }
  }


  /**
   *
   * Replaces rel="lightbox" with class="magnific"
   *
   * @param \DOMDocument $dom
   */
  protected function lightboxToMagnific($dom) {
    foreach ($dom->getElementsByTagName('a') as $link) {
      if ($link->getAttribute('rel') == 'lightbox') {
        $link->removeAttribute('rel');
        $link->setAttribute('class', 'magnific');
      }
    }
  }
  
  
  /**
   *
   * Wraps orphaned previews with magnifying links
   *
   * @param \DOMDocument $dom
   */
  protected function magnifyOrphanPreviews($dom) {
    foreach ($dom->getElementsByTagName('img') as $tag) {
      $parent = $tag->parentNode;
      if ($parent->nodeName == 'a') {
        // already wrapped
        continue;
      }
      $src = $tag->getAttribute('src');
      if (!strstr($src, '.preview.')) {
        // not a preview
        continue;
      }
      // found an orphan, wrap it
      $element = $dom->createElement('a');
      $element->setAttribute('class', 'magnific');
      $element->setAttribute('href', str_replace('.preview', '', $src));
      $parent->replaceChild($element, $tag);
      $element->appendChild($tag);
      $this->output->writeln("Wrapping orphaned preview of $src");
    }
  }
  
  
  /**
   *
   * Replaces code tags with prettify markup,
   * also moves code tags outside of the paragraphs (or prettify won't work)
   *
   * @param \DOMDocument $dom
   * @return bool returns true, if at least one piece of code was processed
   */
  protected function replaceCodeWithPrettify($dom) {
    $return = FALSE;
    foreach ($this->code_tags as $code_tag => $language_class) {
      if ($this->processCodeTag($dom, $code_tag, $language_class)) {
        $return = TRUE;
      }
    }
    return $return;
  }
  
  
  /**
   *
   * Process one code tag to be prettified
   *
   * @param \DOMDocument $dom - dom being processed
   * @param string $code_tag - code tag name
   * @param string $language_class - class to tip Prettify on the language used
   * @return bool returns true if there was a code block processed
   */
  protected function processCodeTag($dom, $code_tag, $language_class) {
    $code_tags = array();
    //get code tags for post
    foreach ($dom->getElementsByTagName($code_tag) as $tag) {
      $code_tags[] = $tag;
    }

    if (empty($code_tags)) {
      return FALSE;
    }
    foreach ($code_tags as $tag) {
      $parent = $tag->parentNode;
      if ($code_tag != 'code') {
        //replace $code_tag with <code class="$language_class">
        $new_tag = $dom->createElement('code');
        foreach ($tag->childNodes as $child) {
          $new_tag->appendChild($child->cloneNode(true));
        }
        $parent->replaceChild($new_tag, $tag);
        $tag = $new_tag;
      }
      if ($parent->nodeName == 'p') {
        $tag = $parent->removeChild($tag);
        $newParent = $parent->parentNode;
        $newParent->insertBefore($tag, $parent->nextSibling);
        $parent = $newParent;
      }
      //wrap code element with <pre></pre>
      $element = $dom->createElement('pre');
      $element->setAttribute('class', 'prettyprint ' . $language_class);
      $parent->replaceChild($element, $tag);
      $element->appendChild($tag);
      //delete brs in code (if any)
      $brs = array();
      foreach ($tag->childNodes as $childNode) {
        if ($childNode->nodeName == 'br') {
          $brs[] = $childNode;
        }
      }
      foreach ($brs as $br) {
        $tag->removeChild($br);
      }
    }
    return TRUE;
  }
  
  
  /**
   *
   * Looks for <object> tags inside <p>, if found, replaces <p> with <div>
   * and align=center with class=text-center
   *
   * @param \DOMDocument $dom
   * @return bool
   */
  protected function replaceObjectP($dom) {
    $return = FALSE;
        
    foreach ($dom->getElementsByTagName('object') as $object) {
      $parent = $object->parentNode;
      if ($parent->nodeName == 'p') {
        // create replacement div
        $div = $dom->createElement('div');
        $div->appendChild($object);
        if ($parent->getAttribute('align') == 'center') {
          // preserve center align
          $div->setAttribute('class', 'text-center');
        }
        $parent->parentNode->replaceChild($div, $parent);
        $return = TRUE;
      }
    }
    
    return $return;
  }


  /**
   * Get the console command arguments.
   * @return array
   */
  protected function getArguments()
  {
    return [
      // input file
      ['input_file', InputArgument::REQUIRED, 'Input CSV file to process'],
      ['output_file', InputArgument::REQUIRED, 'Resulting file name'],
    ];
  }

  /**
   * Get the console command options.
   * @return array
   */
  protected function getOptions()
  {
    return [
      [
        'files',
        NULL,
        InputOption::VALUE_OPTIONAL,
        'Imported files folder path (e.g. /storage/app/old-files, no trailing slash). Set to move file links in content to a new location.',
        NULL,
      ],
      [ 'lightbox-to-magnific', NULL, InputOption::VALUE_NONE, 'If set, rel="lightbox" will be replaced with class="magnific"'],
      [ 'code-to-prettify', NULL, InputOption::VALUE_NONE, 'If set, code tags will be replaced with prettify markup'],
      [
        'replace-external',
        NULL,
        InputOption::VALUE_OPTIONAL,
        'Replace external links to domain given with internal (/* instead of http://domain/*). Type in domain name without protocol.',
        NULL,
      ],
      [
        'disqus-comments',
        NULL,
        InputOption::VALUE_OPTIONAL,
        'Goes over XML file containing comments export for discus and replaces /node/nid link with the new link (/news/year/month/day/slug)',
        NULL,
      ],
      [
        'magnify-orphan-previews',
        NULL,
        InputOption::VALUE_NONE,
        'If there is an image not wrapped in anchor and it has .preview. in src, wrap it with magnifying anchor.',
        NULL,
      ],
      [
        'report-redirects',
        NULL,
        InputOption::VALUE_NONE,
        'If set, will report possible redirects for pages with links different from news/year/month/day/slug.',
        NULL,
      ],
      [
        'report-gallery-links',
        NULL,
        InputOption::VALUE_NONE,
        'If set, will report of links to image galleries in content',
        NULL,
      ],
      [
        'object-p',
        NULL,
        InputOption::VALUE_NONE,
        'If object tag is inside paragraph, replace paragraph with div and align=center with class=text-center'
      ],
    ];
  }


  /**
   * Sets up position of content column
   * Looks through the row of titles to find 'content'
   *
   * @param array $row - row with column titles
   * @param string $column - name of the column to be found
   * @param int $position - value where to save the position
   * @return bool - whether column was found
   */
  protected function findColumn($row, $column, &$position) {
    $pos = 0;
    $found = FALSE;
    foreach ($row as $title) {
      if (strtolower(trim($title)) == $column) {
        $found = TRUE;
        break;
      }
      $pos ++;
    }
    //save what we found
    $position = $pos;
    if ($found) {
      $this->output->writeln("$column was found at column $pos");
    } else {
      $this->error("Can't find column $column");
    }
    return $found;
  }
  
  
  /**
   * Reads file with disqus import to change it through the csv processing and save back later
   */
  protected function readDisqusExport() {
    $this->discus_file = $this->option('disqus-comments');
    $this->discus_text = file_get_contents($this->discus_file);
  }
  
  
  /**
   * Saves changed discus import back
   */
  protected function saveDisqusExport() {
    file_put_contents($this->discus_file, $this->discus_text);
  }
  
}
