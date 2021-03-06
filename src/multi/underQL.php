
<?php

/************************************************************/
/*                          underQL                         */
/************************************************************/
/*                   Abdullah E. Almehmadi                  */
/*                 <cs.abdullah@hotmail.com>                */
/*               6:25 am 26-08-32 : 2011-07-27              */
/*              MPL(Mozilla Public License 1.1)             */
/*        domain registered 6:32 am <www.underql.com>       */
/*                       1.0.0.Beta                         */
/************************************************************/



require_once('config.php');
require_once('langs/'.$UNDERQL['lang']['module'].'.php');
require_once('uti.php');
require_once('rule.php');
require_once('filter.php');
require_once('checker.php');
require_once('plugin.php');

define ('UQL_RULE_MATCHED',0xE1);
define ('UQL_RULE_NOT_MATCHED',0xE2);
define ('UQL_RULE_NOP',0xE3);
/*This is returned if all rules applied with no problems*/
define ('UQL_RULE_OK',0xE4);
define ('UQL_RULE_FAIL',0xE5); // when rule fail of all rules



class UQLRule
{
      private $table_name;
      private $rules;
      private $aliases;
      public  $rules_error_flag;
      public  $rules_error_message;

      /* Initialization */
      public function __construct( $tname )
      {
            $this->table_name = $tname;
            $this->rules = array( );
            $this->rules['UQL']['tablename'] = $tname;
            $this->aliases = array( );
            $this->rules_error_flag = false;
            $this->rules_error_message = '';
      }

       /* Get the name of a table that relates to the rules */
      public function getTableName( )
      {
            return $this->table_name;
      }

      /* Get a list of rules as array*/
      public function getRules( )
      {
            return $this->rules;
      }

      /* Get a list of fields aliases as array */
      public function getAliases( )
      {
            return $this->aliases;
      }

      /*
      Add a new rule.
      $rule_name : Rule name.
      $field : Field name which you want to apply the rule.
      $value : Rule value, sometimes null , single value or any other values like
       array or object.
      */
      private function addRule( $rule_name, $field, $value )
      {
            if ( !isset ( $this->rules[$field] ))
                  $this->rules[$field] = array( );
            if ( is_array( $value ))
                  array_shift( $value );
            // remove the first element becaust it's contains the rule name.
            $this->rules[$field][$rule_name] = $value;
      }

      /*
       Link field name with alias name.
       $name : field name.
       $value : alias name.
      */
      public function addAlias( $name, $value ,$utf8 = true,$charset = 'windows-1256')
      {
            if($utf8)
              $this->aliases[$name] = @iconv($charset, 'UTF-8', $value);
            else
              $this->aliases[$name] = $value;
      }

      /*
       Automatically used when you write the field name as a function to apply
       rules.

       $func : field name.
       $args : the first argument consider as a rule name and based-on the rule
        name, then we can decide the number of remaining args becuse it is differs
        form rule to rule.
      */
      public function __call( $func, $args )
      {
            $l_args_count = @ count( $args );
            if ( $l_args_count == 0 )
                  return;
            else if ( $l_args_count == 1 )
                        $this->addRule( $args[0], $func, true );
                  //args[0] contains the rule name.
            else if ( $l_args_count == 2 )
                              $this->addRule( $args[0], $func, $args[1] );
                        // one value
            else
                     {
                        // array_shift($args);
                              $this->addRule( $args[0], $func, $args );
                              // many values
                     }
      }

      /*
        Excute Rule.
        $rule_name : Rule name.
        $name : field name.
        $value : The passed value by user or any other third-party.
      */
      public function applyRule($rule_name,$name,$value)
      {
        global $UNDERQL;
        $l_rule_callback = $UNDERQL['rule']['uql_prefix'].$rule_name;
        if(!function_exists($l_rule_callback))
         return UQL_RULE_NOP;

         if(isset($this->aliases[$name]))
            $l_result = $l_rule_callback($this->rules,$name,$value,$this->aliases[$name]);
         else
            $l_result = $l_rule_callback($this->rules,$name,$value);

         if(is_string($l_result)) // catch error
         {
           $this->rules_error_message = $l_result;
           $this->rules_error_flag = true;
           return UQL_RULE_NOT_MATCHED;
         }

         return $l_result;

      }


}

class underQL
{

      //used by insert & update instruction
      private $data_buffer;
      // contains the name of all string fields to use it to add single qoute to the value.
      private $string_fields;
      // fields names for the current table.
      private $table_fields_names;
      // this is the array that is used to store fields names for the current query.
      private $fields_of_current_query;
     // table name that is accepting all instructions from the object
      private $table_name;


      // DB connectivity
      public static $db_handle = null;
      // query result
      private $db_query_result;
      //used by fetch method to store fetched row as object.
      private $db_current_object;
      // List of UQLRule objects that are used to store the rules.
      private $rules_objects_list;

      // In filter array that is used to store filters names that will apply in INSERT or UPDATE query.
      private $in_filters;
     // Out filter array that is used to store filters names that will apply in SELECT query.
      private $out_filters;

      // String that contains the last error message and it is used by error method.
      private $err_message;

      /* Initialization
      $tname : table name
      */


      public function __construct( $tname = null )
      {
            global $UNDERQL;

            if(!underQL::$db_handle)
              underQL::$db_handle = @ mysql_connect(
               $UNDERQL['db']['host'],
               $UNDERQL['db']['user'],
               $UNDERQL['db']['password'] );

            if ( !underQL::$db_handle )
                  $this->error( 'Unable to connect to DB..!' );
            if ( !( @ mysql_select_db( $UNDERQL['db']['name'] )))
            {
                  @ mysql_close( underQL::$db_handle );
                  $this->error( 'Unable to select DB..!' );
            }
            @ mysql_query( "SET NAMES '" . $UNDERQL['db']['encoding'] . "'" );

            $this->db_current_object = null;
            $this->db_query_result = false;
            $this->table_fields_names = array( );
            $this->fields_of_current_query = array ( );
            $this->rules_objects_list = array();
            $this->in_filters = array();
            $this->out_filters = array();
            $this->clearDataBuffer( );

            if($tname != null)
              $this->table($tname);
            else
              $this->table_name = $tname;
      }

      /* Clean up*/
      public function __destruct( )
      {
            $this->finish( );
      }

      /*
       Reset the temporary values of the underQL object.
      */
      private function clearDataBuffer( )
      {
            $this->data_buffer = array( );
            $this->err_message = '';
      }

      /*Trigger error message*/
      private function error( $msg )
      {
            global $UNDERQL;
            die( '<code><b><font color ="#FF0000">' . $UNDERQL['error']['prefix'] . '</font></b></code><code>' . $msg . '</code>' );
      }

      /*
      Set current table.
      $tname : table name
      */
      public function table( $tname )
      {
            global $UNDERQL;
            if ( !array_key_exists( $tname, $this->table_fields_names ))
            {
              /* Get tables list for the current database to check if $tname is a valid table name*/
                  $l_result = @ mysql_query( 'SHOW TABLES FROM `' . $UNDERQL['db']['name'] . '`' );
                  $l_count = @ mysql_num_rows( $l_result );
                  if ( $l_count == 0 )
                        $this->error( $tname . ' dose not exist. ' . mysql_error( ));
                  while ( $l_t = @ mysql_fetch_row( $l_result ))
                  {
                        if ( strcmp( $tname, $l_t[0] ) == 0 )
                        {
                              $this->table_name = $tname;
                              @ mysql_free_result( $l_result );
                              $this->readFields( );
                              return;
                        }
                  }
                  if( $l_result )
                     @ mysql_free_result( $l_result );
            }
            else
            {
             /*
             To avoid double check, therefore, if the table exist tables array
              that's menas we don't need to check again*/
                  $this->table_name = $tname;
                  $this->readFields( );
                  return;
            }
            @ mysql_free_result( $l_result );
            $this->error( $tname . ' dose not exist' );
      }

      /*
        Get current table name
      */
      public function getTableName()
      {
        return $this->table_name;
      }

       /*
        Get current table's fields names
      */
      public function getFieldsList()
      {
        return $this->table_fields_names;
      }

      /*
       Get current query fields names as array.
      */
      public function getCurrentQueryFields()
      {
        return $this->fields_of_current_query;
      }
      /*
       It will used when you call underQL object($_) as a function to execute a select query
       $tname : current table.
       $cols  : columns that you want to appear in the query, * for all columns.
       $extra : you can put an extra SQL like WHERE,LIMIT ORDER BY ...etc.
      */
      public function __invoke( $tname = null, $cols = '*', $extra = null )
      {
            if($tname != null)
                 $this->table( $tname );

            return $this->select( $cols, $extra );
      }

      /*
       To apply input filter and it is invoked when we use INSERT OR UPDATE
        SQL command to do something with a value of a specific field.

        $key : field name.
        $val : field value that you would to INSERT or UPDATE it to the table.
      */

      private function applyInFilter($key,$val)
      {
          global $UNDERQL;
          $value = $val;
                 if(isset($this->data_buffer[$key]))
                    {
                      if((isset($this->in_filters[$this->table_name][$key]))&&
                        (@count($this->in_filters[$this->table_name][$key]) != 0))
                        {
                            // apply out filters here
                          $filters_count = @count($this->in_filters[$this->table_name][$key]);
                          $filter_callback = $UNDERQL['filter']['prefix'];
                          $filters_list = $this->in_filters[$this->table_name][$key];
                          for($i = 0; $i < $filters_count; $i++)
                          {
                             $filter_callback = $UNDERQL['filter']['prefix'].$filters_list[$i];
                             $value = $filter_callback($this->data_buffer[$key],UQL_FILTER_IN);
                          }

                        }

                    }
         return $value;

      }

      /*
      Automatically used when you try to INSERT or UPDATE something.

      $key : field name.
      $val : field value.

      */
      public function __set( $key, $val )
      {
            $this->data_buffer[$key] = $val;

            if(isset($this->rules_objects_list[$this->table_name]))
              {
               $l_target = $this->rules_objects_list[$this->table_name];
               if($l_target->rules_error_flag)
                   {
                     $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);
                     return UQL_RULE_FAIL;
                   }
              }

           $l_rules_object_count = @count($this->rules_objects_list);
           if(($l_rules_object_count == 0) || (!isset($this->rules_objects_list[$this->table_name])))
           {
             $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);
              return UQL_RULE_OK;
           }

           $l_target_rule = $this->rules_objects_list[$this->table_name];

           if($l_target_rule == null)
           {
              $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);
              return UQL_RULE_OK;
           }

           $l_rules = $l_target_rule->getRules();

             if((@count($l_rules) == 0) || (strcmp($key,'UQL') == 0) || (!isset($l_rules[$key])))
               {
                 $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);
                 return UQL_RULE_OK;
               }

             $rules_list = $l_rules[$key];

             foreach($rules_list as $rule_name =>$rule_value)
             {
               //value not assigned
               if(!isset($this->data_buffer[$key]))
                continue;

               //Apply filter first
               $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);

               // Check rule second
               if($l_target_rule->applyRule($rule_name,$key,$this->data_buffer[$key])
                  == UQL_RULE_NOT_MATCHED)
               {
                 // $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);
                  return UQL_RULE_FAIL;
               }
             }

           $this->data_buffer[$key] = $this->applyInFilter($key,$this->data_buffer[$key]);

           return UQL_RULE_OK;
      }

      /*
      Check if all rules passed and return TRUE when success, otherwise, return FALSE.
      */
      public function isRulesPassed()
      {
        if(!isset($this->rules_objects_list[$this->table_name]))
         return true;

        //current table's rules object
        $l_target = $this->rules_objects_list[$this->table_name];

        return ($l_target->rules_error_flag == false);
      }

      /*
       if isRulesPassed method return FALSE , then this method will return a string
        that contains the error message, otherwise, return emptry string.
      */
      public function getRuleError()
      {
        if(!isset($this->rules_objects_list[$this->table_name]))
         return '';

        //current table rules object
        $l_target = $this->rules_objects_list[$this->table_name];

        if($l_target->rules_error_flag)
         return $l_target->rules_error_message;

        return '';
      }

      /*
      To attach UQLRule object that contains the rule for current table.
      $rule_object: UQLRule object that contains the rules of the current table fields.
      */
      public function attachRule($rule_object)
      {
          if(!($rule_object instanceof UQLRule))
           return false;

          $rule_object->rules_error_flag = false;

          $this->rules_objects_list[$rule_object->getTableName()] = $rule_object;
          return true;
      }

      /*
      To detach UQLRule object that contains the rule and avoid the current table ruels.
      $rule_object: UQLRule object that contains the rules of the current table fields.
      */
      public function detachRule($tname)
      {
        if(isset($this->rules_objects_list[$tname]))
         $this->rules_objects_list[$tname] = null;
      }

      /*
       This method used by underQL to add single quotes to the non-numeric fields value
        like VARCHAR, TEXT ..etc. However, it is used when we try to formatting the
        INSERT or UPDATE command.
      */
      private function quote( )
      {
            if ( @ count( $this->data_buffer ) == 0 )
                  return;
            foreach ( $this->data_buffer as $key => $val )
            {
                  if ( in_array( $key, $this->string_fields ))
                        $this->data_buffer[$key] = @"'".mysql_real_escape_string($val,underQL::$db_handle)."'";
                  else
                        $this->data_buffer[$key] = $val;
            }
      }


      /*
        Build the INSERT command and return a string that is contains a SQL INSERT command.
      */
      private function formatInsertCommand( )
      {
            $data_buffer_length = @ count( $this->data_buffer );
            if ( $data_buffer_length == 0 )
                  return false;

            $sql = 'INSERT INTO ' . $this->table_name . ' ';
            $sql_columns = '(';
            $sql_values = ' VALUE(';
            $this->quote( );
            $i = 0;
            foreach ( $this->data_buffer as $k => $v )
            {
                  $sql_columns .= $k;
                  $sql_values .= $v;
                  if ( ( $i + 1 ) < $data_buffer_length )
                  {
                        $sql_columns .= ',';
                        $sql_values .= ',';
                  }
                  $i++;
            }
            $sql_columns .= ')';
            $sql_values .= ')';
            $sql .= $sql_columns . $sql_values;
            return $sql;
      }

      /*
       Apply SQL INSERT.
      */
      public function insert( )
      {
            $sql_insert_string = $this->formatInsertCommand( );
            if ( $sql_insert_string == false )
                  return false;

            $l_result = $this->query( $sql_insert_string );
            $this->clearDataBuffer( );
            if($l_result)
             return @mysql_insert_id(underQL::$db_handle);

            return false;
      }

       /*
        Build the UPDATE command and return a string that is contains a SQL UPDATE command.
      */
      private function formatUpdateCommand( $where = null )
      {
            $data_buffer_length = @ count( $this->data_buffer );
            if ( $data_buffer_length == 0 )
                  return false;

            $sql = 'UPDATE ' . $this->table_name . ' SET ';
            $sql_clues = '';
            $this->quote( );
            $i = 0;

            foreach ( $this->data_buffer as $k => $v )
            {
                  $sql_clues .= ' ' . $k . '=' . $v;
                  if ( ( $i + 1 ) < $data_buffer_length )
                        $sql_clues .= ',';
                  $i++;
            }

            $sql .= $sql_clues;

            if ( $where != null )
                  $sql .= ' WHERE ' . $where;
            return $sql;
      }

      /*
       Apply SQL UPDATE.
      */
      public function update( $where = null )
      {
            $sql_update_string = $this->formatUpdateCommand( $where );
            if ( $sql_update_string == false )
                  return false;

            $l_result = $this->query( $sql_update_string );
            $this->clearDataBuffer( );
            return $l_result;
      }

       /*
        Build the DELETE command and return a string that is contains a SQL DELETE command.
      */
      private function formatDeleteCommand( $where = null )
      {
            $sql = 'DELETE FROM ' . $this->table_name;

            if ( $where != null )
                  $sql .= ' WHERE ' . $where;

            return $sql;
      }

     /* Apply SQL DELETE */
      public function delete( $where = null )
      {
            $sql_delete_string = $this->formatDeleteCommand( $where );
            $l_result = $this->query( $sql_delete_string );
            $this->clearDataBuffer( );
            return $l_result;
      }

       /*
        Build the SELECT command and return a string that is contains a SQL SELECT command.
      */
      private function formatSelectCommand( $cols = '*', $extra = null )
      {
            $sql = 'SELECT ' . $cols . ' FROM ' . $this->table_name;

            if ( $extra != null )
                  $sql .= ' ' . $extra;

            return $sql;
      }

      /* Apply SQL SELECT */
      public function select( $cols = '*', $extra = null )
      {
            $sql_select_string = $this->formatSelectCommand( $cols, $extra );

            $l_result = $this->query( $sql_select_string );

            $this->clearDataBuffer( );
            if ( @ mysql_num_rows( $this->db_query_result ) == 0 )
                  return false;

            return $l_result;
      }

      /*
       Fetch one row from the current SELECT result.
      */
      public function fetch( )
      {

          if ( $this->db_query_result )
           {
               $this->db_current_object = @ mysql_fetch_object( $this->db_query_result );
               if(!$this->db_current_object)
                return false;

               $l_fields_names = $this->table_fields_names[$this->table_name];

               if($l_fields_names)
               {
                  foreach ($l_fields_names as $field_index => $field_name)
                 {
                    if(@isset($this->db_current_object->$field_name))
                    {
                       if(@array_key_exists($field_name,$this->out_filters[$this->table_name]))
                         $this->db_current_object->$field_name = $this->applyOutFilter($field_name);
                    }
                 }
                 return true;
               }
               else
                return true;
           }
           else
            return false;

      }

      /* Get the count of the last SQL SELECT query */
      public function count( )
      {
            if ( !$this->db_query_result )
                  return 0;

            return @ mysql_num_rows( $this->db_query_result );
      }

      /* Get the number of affected rows from the last query */
      public function affected( )
      {
            if ( !underQL::$db_handle )
                  return 0;

            return @ mysql_affected_rows( underQL::$db_handle );
      }

      /*
       Free the result from the last SELECT query.
      */
      public function free( )
      {
            if ( $this->db_query_result )
                  @ mysql_free_result( $this->db_query_result );
      }

      /*
      To apply your IN or OUT filter.
      $filter_name : The first parameter that is accepting the filter name.
      $filter_dir : The second parameter that is acceptiong the dirction of the data
       and its value are : UQL_FILTER_IN which is used with INSERT and UPDATE queries,
       and UQL_FILTER_OUT which is used with SELECT queries.

       After that you can use any number of parameters to specifying the names of the
       fields that you want to apply $filter_name on them.
      */
      public function filter( )
      {
            global $UNDERQL;
            $l_args_num = func_num_args( );
            if ( $l_args_num < 3 )
                  return false;
            $filter_name = func_get_arg( 0 );

            $l_filter_callback = $UNDERQL['filter']['prefix'] . $filter_name;

            if ( !function_exists( $l_filter_callback ))
                  return false;

            switch(func_get_arg(1))
            {
              case UQL_FILTER_IN:

               for ( $i = 2; $i < $l_args_num; $i++ )
                  {
                    if(!isset($this->in_filters[$this->table_name][func_get_arg( $i )]))
                     $this->in_filters[$this->table_name][func_get_arg( $i )] = array($filter_name);
                    else
                      {
                        $_temp = $this->in_filters[$this->table_name][func_get_arg( $i )];
                        $_temp[@count($_temp)] = $filter_name;

                        $this->in_filters[$this->table_name][func_get_arg( $i )] = $_temp;
                      }
                  }
                  return true;

              case UQL_FILTER_OUT:
               for ( $i = 2; $i < $l_args_num; $i++ )
                  {
                    if(!isset($this->out_filters[$this->table_name][func_get_arg( $i )]))
                    {
                       $this->out_filters[$this->table_name][func_get_arg( $i )] = array($filter_name);
                    }
                    else
                      {
                        $_temp = $this->out_filters[$this->table_name][func_get_arg( $i )];
                        $_temp[@count($_temp)] = $filter_name;

                        $this->out_filters[$this->table_name][func_get_arg( $i )] = $_temp;

                      }
                  }
                  return true;
                  default : return false;
            }
      }

      /*
      To apply a checker. Checker is a method that is call your function
       and return TRUE or FALSE based-on the situation.
       $checker : Checker name.
       $value : The value entred by user or any other third-party.
      */
      public function checker( $checker, $value )
      {
            global $UNDERQL;
            $checker_callback = $UNDERQL['checker']['prefix'] . $checker;

            if ( !function_exists( $checker_callback ))
                  return false;
            return $checker_callback( $this->data_buffer[$value] );
      }

      /*
       Excute SQL query.
       $query : SQL query string.
      */
      public function query( $query )
      {
            $this->free( );
            $this->db_query_result = @ mysql_query( $query );
            $this->fields_of_current_query = array();
            if ( $this->db_query_result )
                {
                    $l_fnum = @mysql_num_fields($this->db_query_result);
                    if($l_fnum > 0)
                    {
                      for($i = 0; $i < $l_fnum; $i++)
                       $this->fields_of_current_query[] = mysql_field_name($this->db_query_result,$i);
                      //var_dump($this->fields_of_current_query);
                    }
                    return true;
                }

            return false;
      }


      /*
          To apply filter on data when you excute SELECT query.
          $key : Field name the you want to apply output filter on it.
      */
      private function applyOutFilter($key)
      {
          global $UNDERQL;
          $value = null;

            if ( isset($this->db_current_object) )
              {
                 if(isset($this->db_current_object->$key))
                    {
                      $value = $this->db_current_object->$key;
                      if((isset($this->out_filters[$this->table_name][$key]))&&
                        (@count($this->out_filters[$this->table_name][$key]) != 0))
                        {          $value = $this->db_current_object->$key;
                            // apply out filters here

                          $filters_count = @count($this->out_filters[$this->table_name][$key]);
                          $value = $this->db_current_object->$key;
                          $filter_callback = $UNDERQL['filter']['prefix'];
                          $filters_list = $this->out_filters[$this->table_name][$key];
                          for($i = 0; $i < $filters_count; $i++)
                          {
                             $filter_callback = $UNDERQL['filter']['prefix'].$filters_list[$i];
                             $value = $filter_callback($this->db_current_object->$key,UQL_FILTER_OUT);
                          }

                        }

                      return $value;
                    }
              }

            return ((isset($this->db_current_object->$key)) ? $this->db_current_object->$key : null);
      }

      /*
        Automatically invoked when you ask to get a field value.
        $key : Field name.
      */
      public function __get( $key )
      {
         if(isset($this->db_current_object->$key))
             return $this->db_current_object->$key;
         else
             return null;
      }

      /*
       Read the names of the fields for the current selected table and store them
        in an array.
        NOTE : We use it also to store another array that is contains
        the non-numerical fields becuase we need this array to help us when we calling
        the quote method to add a single quotes.
      */
      private function readFields( )
      {
            global $UNDERQL;

            $l_fs = @ mysql_list_fields( $UNDERQL['db']['name'], $this->table_name );
            $l_fq = @ mysql_query( 'SHOW COLUMNS FROM `' . $this->table_name . '`' );
            $l_fc = @ mysql_num_rows( $l_fq );
            @ mysql_free_result( $l_fq );
            $i = 0;

            $this->table_fields_names[$this->table_name] = array( );
            $this->string_fields = array( );

            while ( $i < $l_fc )
            {
                  $l_f = mysql_fetch_field( $l_fs );
                  if ( $l_f->numeric != 1 )
                   $this->string_fields[@ count( $this->string_fields )] = $l_f->name;

                   $this->table_fields_names[$this->table_name]
                     [@count($this->table_fields_names[$this->table_name])]  = $l_f->name;
                  $i++;
            }
      }

      public function isPluginExist($plugin)
      {
        global $UNDERQL;
        return function_exists($UNDERQL['plugin']['api_prefix'].$plugin);
      }

      /*
        Used to apply plugin.
        $func : plugin name.
        $args : plubin arguments.
      */

      public function __call($func,$args)
      {
         global $UNDERQL;

         if($this->isPluginExist($func))
          {
             $plugin_callback = $UNDERQL['plugin']['api_prefix'].$func;
             return $plugin_callback($this,$args);
          }

         $this->error('Call to undefined method/plugin :'.$func);
         //return UQL_PLUGIN_RETURN;

      }

      /*
        Get one record based-on its ID.
        $ival : id value.
        $fname: field name.Default value is [id] because it is most common.
      */

      public function getByID($ival,$fname = 'id')
      {
        $this->select('*','WHERE '.$fname.' = '.$ival);
        if($this->count() == 0)
         return null;

        $this->fetch();
        return $this->db_current_object;
      }

      /*
        Get records based-on its ($fname) field value($ival).
        $fname: field name.
        $ival : id value.
      */
      public function getBy($fname,$ival)
      {
        if(!in_array($fname,$this->table_fields_names[$this->table_name]))
         return false;

        if(in_array($fname,$this->string_fields))
         $value = "'".$ival."'";
        else
         $value = $ival;

        $this->select('*','WHERE '.$fname.' = '.$value);
         if($this->count() == 0)
         return false;

         return true;

      }

      /*
       To insert or update data that are comming from array.
       $op : if 'i' then, insert, otherwise, update.
       $value : array of values.
       $extra : used with update if you would like to put a condition.
      */
      private function opFromArray($op,$values,$extra = null)
      {
        if(!is_array($values))
         return false;

        $val_counts = @count($values);
        if($val_counts == 0)
         return false;

         foreach($values as $key => $val)
         {
           if(in_array($key,$this->table_fields_names[$this->getTableName()]))
             $this->$key = $val;
         }

         if(@count($this->data_buffer) == 0)
          return false;

         if(!$this->isRulesPassed())
          return $this->getRuleError();

         if(strcmp(strtolower($op),'i') == 0)
            return $this->insert();
         else
            return $this->update($extra);
      }

       /*
       To insert data that are comming from array.
       $value : array of values.
      */
       public function insertFromArray($values)
       {
         return $this->opFromArray('i',$values);
       }

       /*
       To update data that are comming from array.
       $value : array of values.
       $extra : used with update if you would like to put a condition.
      */
       public function updateFromArray($values,$extra = null)
       {
         return $this->opFromArray('u',$values,$extra);
       }
      /*
       Free the database results and close the database.
      */
      public function finish( )
      {
            $this->clearDataBuffer();
            $this->free( );
            if ( underQL::$db_handle )
                  @ mysql_close( underQL::$db_handle );
      }


}

/* underQL instance (object) */

   $_ = new underQL( );
   $underQL = &$_;

   $_('users');
   while($_->fetch())
   {
     echo $_->name.'<br />';
   }
?>