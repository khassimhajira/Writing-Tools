/**
 * @license Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */
"use strict";CKEDITOR.dialog.add("placeholder",(function(e){var t=e.lang.placeholder,a=e.lang.common.generalTab;return{title:t.title,minWidth:300,minHeight:80,contents:[{id:"info",label:a,title:a,elements:[{id:"select",type:"select",label:t.name,items:e.config.placeholder_items,default:"",required:!0,validate:CKEDITOR.dialog.validate.notEmpty(t.invalidName),setup:function(e){this.setValue("%"+e.data.name+"%")},commit:function(e){e.setData("name",this.getValue().slice(1,-1))}}]}]}}));