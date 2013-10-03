<?php
  
  namespace StructuredDynamics\osf\ws\revision\read\interfaces; 
  
  use \StructuredDynamics\osf\ws\framework\SourceInterface;
  use \StructuredDynamics\osf\framework\Subject;
  use \StructuredDynamics\osf\framework\Namespaces;
  
  class DefaultSourceInterface extends SourceInterface
  {
    function __construct($webservice)
    {   
      parent::__construct($webservice);
      
      $this->compatibleWith = "3.0";
    }
    
    public function processInterface()
    {  
      // Make sure there was no conneg error prior to this process call
      if($this->ws->conneg->getStatus() == 200)
      {  
        $subjectUri = $this->ws->revuri;
            
        $revisionsDataset = rtrim($this->ws->dataset, '/').'/revisions/';
        
        if($this->ws->memcached_enabled)
        {
          $key = $this->ws->generateCacheKey('revision-read', array(
            $subjectUri,
            $revisionsDataset
          ));
          
          if($return = $this->ws->memcached->get($key))
          {
            $this->ws->setResultset($return);
            
            return;
          }
        }          
        
        // Archiving suject triples
        $query = $this->ws->db->build_sparql_query("
          select ?p ?o (DATATYPE(?o)) as ?otype (LANG(?o)) as ?olang 
          from <" . $revisionsDataset . "> 
          where 
          {
            <".$this->ws->revuri."> ?p ?o.
          }", 
          array ('p', 'o', 'otype', 'olang'), FALSE);

        $resultset = $this->ws->db->query($query);

        if(odbc_error())
        {
          $this->ws->conneg->setStatus(500);
          $this->ws->conneg->setStatusMsg("Internal Error");
          $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_303->name);
          $this->ws->conneg->setError($this->ws->errorMessenger->_303->id, $this->ws->errorMessenger->ws,
            $this->ws->errorMessenger->_303->name, $this->ws->errorMessenger->_303->description, odbc_errormsg(),
            $this->ws->errorMessenger->_303->level);
        }
        
        $subject = Array("type" => Array(),
                         "prefLabel" => "",
                         "altLabel" => Array(),
                         "prefURL" => "",
                         "description" => "");           
       
        $found = FALSE; 
        while(odbc_fetch_row($resultset))
        {
          $found = TRUE;
          $p = odbc_result($resultset, 1);
          
          $o = $this->ws->db->odbc_getPossibleLongResult($resultset, 2);

          $otype = odbc_result($resultset, 3);
          $olang = odbc_result($resultset, 4);

          $objectType = "";
          
          if($this->ws->mode == 'record')
          {
            if($p == Namespaces::$wsf.'revisionUri')
            {
              $subjectUri = $o;
            }
            
            if($p == Namespaces::$wsf.'revisionUri' ||
               $p == Namespaces::$wsf.'fromDataset' ||
               $p == Namespaces::$wsf.'revisionTime' ||
               $p == Namespaces::$wsf.'performer' ||
               $p == Namespaces::$wsf.'revisionStatus')              
            {
              continue;
            }
          }            
          
          $objectType = "";
          
          if($olang && $olang != "")
          {
            /* If a language is defined for an object, we force its type to be xsd:string */
            $otype = "http://www.w3.org/2001/XMLSchema#string";
          }
          
          // Since the default datatype is rdfs:Literal, we put nothing as the type if the $otype
          // is xsd:string
          // Note: we may eventually want to keep the xsd:string type assignation. If it is the
          //       case then we will only have to remove the 4 lines below.
          if($otype == 'http://www.w3.org/2001/XMLSchema#string')
          {
            $otype = '';
          }
          
          $objectType = $otype;
  
          if($p == Namespaces::$rdf."type")
          {
            if($this->ws->mode == 'record' && $o == Namespaces::$wsf.'Revision')
            {
              continue;
            }
            
            if(array_search($o, $subject["type"]) === FALSE)
            {
              array_push($subject["type"], $o);
            }
          }
          else
          {
            if(!isset($subject[$p]) || !is_array($subject[$p]))
            {
              $subject[$p] = array();
            }
            
            if($objectType !== NULL)
            {
              array_push($subject[$p], Array("value" => $o, 
                                             "lang" => (isset($olang) ? $olang : ""),
                                             "type" => $objectType));
            }
            else
            {
              array_push($subject[$p], Array("uri" => $o, 
                                             "type" => ""));
            }
          }
        }
        
        // Get reification triples
        $query = "select ?rei_p ?rei_o ?p ?o from <" . $revisionsDataset . "> 
                  where 
                  {
                    ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#subject> <".$this->ws->revuri.">.
                    ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#predicate> ?rei_p.
                    ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#object> ?rei_o.
                    ?statement ?p ?o.
                  }";
      
        $query = $this->ws->db->build_sparql_query(str_replace(array ("\n", "\r", "\t"), " ", $query),
          array ('rei_p', 'rei_o', 'p', 'o'), FALSE);

        $resultset = $this->ws->db->query($query);

        if(odbc_error())
        {
          $this->ws->conneg->setStatus(500);
          $this->ws->conneg->setStatusMsg("Internal Error");
          $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_304->name);
          $this->ws->conneg->setError($this->ws->errorMessenger->_304->id, $this->ws->errorMessenger->ws,
            $this->ws->errorMessenger->_304->name, $this->ws->errorMessenger->_304->description, odbc_errormsg(),
            $this->ws->errorMessenger->_304->level);
        }

        while(odbc_fetch_row($resultset))
        {
          $rei_p = odbc_result($resultset, 1);
          $rei_o = $this->ws->db->odbc_getPossibleLongResult($resultset, 2);
          $p = odbc_result($resultset, 3);
          $o = $this->ws->db->odbc_getPossibleLongResult($resultset, 4);

          if($p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#subject" &&
             $p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#predicate" &&
             $p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#object" &&
             $p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#type")
          {
            foreach($subject[$rei_p] as $key => $value)
            {
              if((isset($value["uri"]) && $value["uri"] == $rei_o) ||
                 (isset($value["value"]) && $value["value"] == $rei_o))
              {
                if(!isset($subject[$rei_p][$key]["reify"]))
                {
                  $subject[$rei_p][$key]["reify"] = array();
                }
                
                if(!isset($subject[$rei_p][$key]["reify"][$p]))
                {
                  $subject[$rei_p][$key]["reify"][$p] = array();
                }
                
                array_push($subject[$rei_p][$key]["reify"][$p], $o);
              }
            }
          }
        }

        if($found)
        {
          if(!isset($subject[Namespaces::$dcterms.'isPartOf']))
          {
            $subject[Namespaces::$dcterms.'isPartOf'] = array(array("uri" => $this->ws->dataset, 
                                                                    "type" => ""));
          }
          
          unset($resultset);

          $this->ws->rset->setResultset(Array($this->ws->dataset => array($subjectUri => $subject)));
          
          if($this->ws->memcached_enabled)
          {
            $this->ws->memcached->set($key, $this->ws->rset, NULL, $this->ws->memcached_revision_read_expire);
          }     
        }
        else
        {
          $this->ws->conneg->setStatus(400);
          $this->ws->conneg->setStatusMsg("Bad Request");
          $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_306->name);
          $this->ws->conneg->setError($this->ws->errorMessenger->_306->id, $this->ws->errorMessenger->ws,
            $this->ws->errorMessenger->_306->name, $this->ws->errorMessenger->_306->description, odbc_errormsg(),
            $this->ws->errorMessenger->_306->level);            
        }
      }      
    }
  }
?>