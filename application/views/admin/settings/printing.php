<div class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1 class="m-0 text-dark"><?php echo _l("Printing Settings"); ?></h1>
      </div>
      
    </div>
  </div>
</div>
<section class="content">
<div class="container-fluid">

    <div class="row">
        <div class="col-md-12">

            <div class="card card-primary">
                <div class="card-header border-transparent">
                    <h3 class="card-title"><?php echo _l("Printing settings"); ?></h3>
                   
                </div>

                <div class="card-body">
            <?php
            
            echo _get_flash_message();
            echo form_open_multipart();
			?>
			<div class="col-md-12">
				<label><?php echo _l("Enable"); ?></label>
				<div class="btn-group">
				<label class="btn">
					<input type="radio" name="print_auto" id="print_auto" value="auto" onclick="active_print_auto_method()" <?php echo (_get_post_back($field,'print_auto')=='auto')?"checked":""; ?>> <?php echo _l("Auto"); ?>
				</label>
				<label class="btn">
					<input type="radio" name="print_auto" id="print_auto" value="manual" onclick="active_print_auto_method()" <?php echo (_get_post_back($field,'print_auto')=='manual')?"checked":""; ?>> <?php echo _l("Manual"); ?> 
				</label>
                </div>
                
                <div class="btn-group float-md-right">
                </div>
			</div>
			<?php
			echo '<div class="clearfix"></div>';
			echo '<div id="auto_div">';
			echo "<blockquote>";
            echo _l("You have to use following PrintNode API Key.");
            echo "</blockquote>";
            echo '<div class="clearfix"></div>';
            ?>
            <?php
			echo _input_field("printnode_api_key", _l("API Key")."<span class='text-danger'>*</span>", _get_post_back($field,'printnode_api_key'), 'text', array("data-validation" =>"required"),array(),"col-md-12");
            
            echo '<div class="clearfix"></div>';
			echo '</div>';
            ?>
            <?php
            echo '<div class="clearfix"></div>';
			//https://sendgrid.com/
			echo '<div class="col-md-12">
				<button type="submit" class="btn btn-primary btn-flat">'._l("Save").'</button>&nbsp;';
			echo '</div>';
            echo form_close();
            ?>
        </div>
    </div>
        </div>
    </div>
</div>
    <!-- /.box -->
</section>