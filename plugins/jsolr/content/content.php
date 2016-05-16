<?php
/**
 * @package     JSolr.Plugin
 * @subpackage  Index
 * @copyright   Copyright (C) 2012-2016 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die();

jimport('legacy.table.category');

use \JSolr\Index\Crawler;
use \JSolr\Helper;

class plgJSolrCrawlerContent extends JSolrIndexCrawler
{
    protected $context = 'com_content.article';

    /**
    * Prepares an article for indexing.
    */
    protected function getDocument(&$record)
    {
        $doc = new JSolrApacheSolrDocument();

        $created = JFactory::getDate($record->created);
        $modified = JFactory::getDate($record->modified);

        if ($created > $modified) {
            $modified = $created;
        }

        $lang = $this->getLanguage($record, false);

        $doc->addField('created', $created->format('Y-m-d\TH:i:s\Z', false));
        $doc->addField('modified', $modified->format('Y-m-d\TH:i:s\Z', false));
        $doc->addField("title", $record->title);
        $doc->addField("title_$lang", $record->title);
        $doc->addField("access", $record->access);

        $doc->addField("title_ac", $record->title); // for auto complete

        $record->summary = JSolrHelper::prepareContent($record->summary, $record->params);
        $record->body = JSolrHelper::prepareContent($record->body, $record->params);

        $doc->addField("body_$lang", strip_tags($record->summary));
        $doc->addField("body_$lang", strip_tags($record->body));

        foreach (explode(',', $record->metakey) as $metakey) {
            $doc->addField("metakeywords_$lang", trim($metakey));
        }

        $doc->addField("metadescription_$lang", $record->metadesc);
        $doc->addField("author", $record->author);

        $doc->addField("author_fc", $this->getFacet($record->author)); // for faceting
        $doc->addField("author_ac", $record->author); // for auto complete

        foreach (JSolrHelper::getTags($record, array("<h1>")) as $item) {
            $doc->addField("tags_h1_$lang", $item);
        }

        foreach (JSolrHelper::getTags($record, array("<h2>", "<h3>")) as $item) {
            $doc->addField("tags_h2_h3_$lang", $item);
        }

        foreach (JSolrHelper::getTags($record, array("<h4>", "<h5>", "<h6>")) as $item) {
            $doc->addField("tags_h4_h5_h6_$lang", $item);
        }

        $doc->addField("hits_i", (int)$record->hits);

        if ($record->catid) {
            $doc->addField("parent_id", $record->catid);
            $doc->addField("category_$lang", $record->category);
            $doc->addField("category_fc", $this->getFacet($record->category)); // for faceting
        }

        return $doc;
    }

    protected function buildQuery()
    {
        // Create a new query object.
        $db        = JFactory::getDbo();
        $query    = $db->getQuery(true);
        $user    = JFactory::getUser();

        // Select the required fields from the table.
        $query->select('a.id, a.title, a.alias, a.introtext AS summary, a.fulltext AS body');
        $query->select('a.state, a.catid, a.created, a.created_by, a.hits');
        $query->select('a.created_by_alias, a.modified, a.modified_by, a.attribs AS params');
        $query->select('a.metakey, a.metadesc, a.metadata, a.language, a.access, a.version, a.ordering');
        $query->select('a.publish_up AS publish_start_date, a.publish_down AS publish_end_date');

        $query->from('#__content AS a');

        // Join over the users for the checked out user.
        $query->select('uc.name AS editor');
        $query->join('LEFT', '#__users AS uc ON uc.id=a.checked_out');

        // Join over the asset groups.
        $query->select('ag.title AS access_level');
        $query->join('LEFT', '#__viewlevels AS ag ON ag.id = a.access');

        // Join over the categories.
        $query->select('c.title AS category, c.published AS cat_state, c.access AS cat_access');
        $query->join('LEFT', '#__categories AS c ON c.id = a.catid');

        // Join over the users for the author.
        $query->select('ua.name AS author');
        $query->join('LEFT', '#__users AS ua ON ua.id = a.created_by');

        $conditions = array();

        $categories = $this->params->get('categories');

        if (is_array($categories)) {
            if (JArrayHelper::getValue($categories, 0) != 0) {
                JArrayHelper::toInteger($categories);
                $categories = implode(',', $categories);
                $conditions[] = 'a.catid IN ('.$categories.')';
            }
        }

        if ($lastModified = JArrayHelper::getValue($this->get('indexOptions'), 'lastModified', null, 'string')) {
            $lastModified = JFactory::getDate($lastModified);

            $conditions[] = "(a.created > '".$lastModified."' OR a.modified > '".$lastModified."')";
        }

        if (count($conditions)) {
            $query->where($conditions);
        }

        return $query;
    }
}
