<?php 
class mssqlnative_2_postgres9 extends generic_driver{
	
	public $source;
	public $dest;
	
	public $exclude_list = Array('sysdiagrams');
	
	public function __construct(){
	
	}
	
	public function set_source_dest($source, $dest){
		$this->source = $source;
		$this->dest = $dest;
	}
	
		
	public function clone_table($table_name){
		if(in_array($table_name, $this->exclude_list))
			throw new Exception("SKIPPED: table in exclude_list");
		$primary_keys = $this->source->MetaPrimaryKeys($table_name);
		$text="";
		$j=0;
		$dict = NewDataDictionary($this->dest);
		
		try{
			$sqlarray = $dict->DropTableSQL($table_name);
			$dict->ExecuteSQLArray($sqlarray);
		} catch (Exception $e){
			if($e->getCode()!=-1)
				throw new Exception("UNDEFINED ERROR WHILE DELETING THE TABLE");
		}
		foreach($this->source->MetaColumns($table_name) as $column => $fields){
			$j=$j+1;
			// echo $column;
			if($fields->type=="int"){
				$type="I";
				$text.="$fields->name $type ($fields->max_length)";
			} elseif($fields->type=="bit"){
				$type="BOOLEAN";
				$text.="$fields->name $type";
			} elseif($fields->type=="nvarchar"){
				$type="C2";
				if($fields->max_length==-1)
					$text.="$fields->name $type";
				else
					$text.="$fields->name $type ($fields->max_length)";
			} elseif($fields->type=="varchar"){
				$type="X";
				$text.="$fields->name $type";
			} elseif($fields->type=="char"){
				$type="CHAR";
				$text.="$fields->name $type ($fields->max_length)";
			} elseif($fields->type=="nchar"){
				$type="CHAR";
				$text.="$fields->name $type ($fields->max_length)";
			} elseif($fields->type=="datetime"){
				$type="T";
				$text.="$fields->name $type";
			} elseif($fields->type=="float"){
				$type="F";
				$text.="$fields->name $type";
			} elseif($fields->type=="bigint"){
				$type="I8";
				$text.="$fields->name $type";
			} elseif($fields->type=="ntext"){
				$type="TEXT";
				$text.="$fields->name $type";
			}else{
				$type="XXXX";
				throw new Exception("ERROR: NOT IMPLEMENTED FIELD ".$fields->type);
			}

			if($fields->is_identity){
				$identity_field = $fields->name;
				$text.=" AUTOINCREMENT";
			}
			if($primary_keys){	
				if(in_array($fields->name, $primary_keys))
					$text.=" PRIMARY"; 
			}
			if($j<count($this->source->MetaColumns($table_name)))
				$text.=",";	
		}
		$sqlarray = $dict->CreateTableSQL($table_name, $text);
		$dict->ExecuteSQLArray($sqlarray);
		if(isset($identity_field)){
			$sql = "select max($identity_field) as next_identity from $table_name";
			$result = $this->source->Execute($sql);
			$next_identity = $result->fields['next_identity']+1;
			$sql = "ALTER SEQUENCE ".$table_name."_".$identity_field."_seq RESTART WITH $next_identity INCREMENT BY 1";
			$result = $this->dest->Execute($sql);
		}
		return($text);
	}
	
	public function count_rows($table_name){
		$sql="select count(*) as num_rows from $table_name";
		$risultato=$this->source->Execute($sql);
		return($risultato->fields['num_rows']);
	}
	
	public function get_view_definition($view_name){
		$sql="select * from INFORMATION_SCHEMA.views where TABLE_NAME ='$view_name'";
		$risultato=$this->source->Execute($sql);
		$view_definition = $risultato->fields['VIEW_DEFINITION'];
		// ELIMINATING THE CREATE VIEW STATEMENT
		$view_definition = substr($view_definition, strpos($view_definition, "SELECT"));
		$table_schema = $risultato->fields['TABLE_SCHEMA'];
		// ELIMINATING THE SCHEMA.TABLE NOTATION (like dbo.users)
		$view_definition = str_replace($table_schema.".","",$view_definition);
		// REPLACING THE ISNULL FUNCTION (IF PRESENT) WITH coalesce (coalesce can also be used in MSSQL..)
		$view_definition = str_replace("ISNULL(","coalesce(",$view_definition);
		// REPLACING MSSQL KEYWORD-LIKE COLUMN NAMES IN POSTGRE STYLE (replacing [] with ""..)
		$view_definition = str_replace("[","\"",$view_definition);
		$view_definition = str_replace("]","\"",$view_definition);
		return($view_definition);
	}
	
	public function import_rows($table_name, $rows_x_request, $offset){
		$sql="select * from $table_name";
		$risultato=$this->source->SelectLimit($sql, $rows_x_request, $offset);
		$sql_insert = "insert into $table_name (#COLUMN_NAME#) values (#COLUMN_VALUE#)";
		$columns = $this->source->MetaColumns($table_name);
		$i=0;
		foreach($columns as $column => $fields){
			if(count($columns)-1==$i){
				$sql_insert = str_replace("#COLUMN_NAME#", $fields->name, $sql_insert);
				$sql_insert = str_replace("#COLUMN_VALUE#", "#".$fields->name."#", $sql_insert);
			} else {
				$sql_insert = str_replace("#COLUMN_NAME#", $fields->name.", #COLUMN_NAME#", $sql_insert);
				$sql_insert = str_replace("#COLUMN_VALUE#", "#".$fields->name."#, #COLUMN_VALUE#", $sql_insert);
			}
			$i=$i+1;
		}
		while(!$risultato->EOF){
			$temp = $sql_insert;
			foreach($risultato->fields as $field => $value){
				foreach($columns as $column => $fields){
					if($fields->name==$field)
						$type=$fields->type;
				}
				if($type=="bit"){
					if($value==0)
						$value='false';
					else
						$value='true';
				} elseif($type=="nvarchar"||$type=="nchar"||$type=="ntext"){
					$value = $this->dest->qstr(Encoding::fixUTF8($value));
				} elseif($type=="varchar"||$type=="char"){
					$value = $this->dest->qstr(Encoding::fixUTF8($value));
				} elseif($type=="int"){
					if(!isset($value))
						$value="NULL";
				} elseif($type=="datetime"){
					$value = $this->dest->DBDate($value);
				}
				$temp = str_replace("#".$field."#", $value, $temp);
			}
			$this->dest->Execute($temp);
			$risultato->MoveNext();
		}
	}
	
	public function clone_view($view_name){
		$view_definition = $this->get_view_definition($view_name);
		try{
			$sql="CREATE OR REPLACE VIEW $view_name AS $view_definition";
			$risultato = $this->dest->Execute($sql);
		} catch(Exception $e){
			throw $e;
		}
		return($view_definition);
	}
	
	public function sort_views_by_dependency(){
		$views=Array();
		foreach($this->source->MetaTables('VIEWS') as $key => $value){
			$views[]= Array(
				"name" => $value,
				"definition" => $this->get_view_definition($value),
				"dependencies" => Array()
			);
		}
		 
		usort($views, array('self','cmp_len')); 
		
		// BUILD THE DEPENDENCY TABLE
		foreach($views as $key => $view){
			foreach($views as $depends_from_view){
				if(strpos($views[$key]['definition'], $depends_from_view['name'])===false){
				} else {
					$views[$key]['definition'] = str_replace($depends_from_view['name'],"#####",$views[$key]['definition']);
					$views[$key]['dependencies'][]=$depends_from_view['name'];
				}
			}
		}
		
		// SORT THE VIEWS IN AN ORDER THAT MAKES THEM CREABLE WITH DEPENDENCIES RESOLVED
		$sorted_views = Array();
		$i=0;
		while(count($views)>0&&$i<5){
			foreach($views as $key => $cur_view){
				foreach($cur_view['dependencies'] as $index => $dependency){
					if(in_array($dependency,$sorted_views)){
						unset($views[$key]['dependencies'][$index]);
					}
				}
				if(count($views[$key]['dependencies'])==0){
					$sorted_views[]=$cur_view['name'];
					unset($views[$key]);
				}
			}
			$i=$i+1;
		}
		return($sorted_views);
	}
		
	public function clone_index($table_name, $index_name){
		if(in_array($table_name, $this->exclude_list))
			throw new Exception("SKIPPED: table $table_name in exclude_list");
		$indexes = $this->source->MetaIndexes($table_name);
		$cur_index = $indexes[$index_name];
		$columns = $cur_index['columns'];
		$columns_string="";
		foreach($columns as $index => $c){
			if($index==count($columns)-1)
				$columns_string.=$c;
			else
				$columns_string.=$c.", ";
		}
		try{
			$dict = NewDataDictionary($this->dest);
			$sqlarray = $dict->CreateIndexSQL($index_name, $table_name, $columns_string);
			$dict->ExecuteSQLArray($sqlarray);
		} catch(Exception $e){
			throw $e;
		}
		return($sqlarray);
	}

	private static function cmp_len($a, $b){ 
	   return (strlen($a['name'])  < strlen($b['name']));
	}
	/* THIS WILL BE USED FOR PAGINATION PURPOSES
	public function count_rows($table_name){
		$sql="select * from $table_name";
		$rows_x_page = 2000;
		$num_rows = 2000;
		$i=0;
		$cumulative =0;
		while($num_rows==$rows_x_page){
			$risultato=$this->source->SelectLimit($sql, $rows_x_page, $i*$rows_x_page);
			$num_rows=$risultato->RecordCount();
			$i=$i+1;
			$cumulative = $cumulative+$num_rows;
		}
		return($cumulative);
	}
	*/
	
}
?>