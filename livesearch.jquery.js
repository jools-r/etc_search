/*	etc_search v0.9.4 for jQuery */
var etc_live_search=(function($){
function u(t,x,f){t.trigger("update."+f,x).trigger("stop");}
function b(t,h,z){if(z.a)z.a.abort();if(z.s)clearTimeout(z.s);z.s=setTimeout(function(){var c='',i=h?h.find(":input").not("[data-etc='search'],:hidden"):null;if(i)$.each(i.serializeArray(),function(k,v){c+=v.value.trim()});if(c.length<z.m)u(t,'',z.f);else{t.trigger("start");z.a=$.post(z.url, z.o+'&'+i.serialize()).always(function(x){u(t,x,z.f)})}},h?z.l:0);}
return function(l,m,y,t,o){var f=document.getElementById(y),h=$(f);if(!t) h.append($(t=$("<div style='display:none' class='ls_results'/>")));else t=$(t);t.bind("update."+y,function(e,x){t.etcShow(x)});if(l<=0)l=-l;else h.on('focusout',function(e){if(z.s)clearTimeout(z.s);z.s=setTimeout(function(){if(!e.delegateTarget.contains(document.activeElement)){h.trigger('reset');}},l);});f.addEventListener("input",function(){b(t,h,z)});f.addEventListener("reset",function(){b(t,null,z)});h.on("change","select,:checkbox,:radio",function(e){b(t,h,z)});
$.each(h.find(":input[data-etc='search']").serializeArray(),function(k,v){if(o[v.name]==null)o[v.name]=v.value});var z={o:$.param(o),a:false,s:false,f:y,l:l,m:m,url:h.attr("action")};}
})(jQuery);

(function($)
{
   $.fn.etcShow = function(html, speed, callback)
   {
      return this.each(function()
      {
         var el = $(this);
         el.stop(true,true);
         var def = {width: this.style.width, height: this.style.height};
         var cur = {width: el.width(), height: el.height()};
         // Modify the element's contents. Element will resize.
         el.html(html);
         var next = {width: el.width(), height: el.height()};
         if(html) el.show();
         el.css(cur) // restore initial dimensions
            .animate(next, speed, function()  // animate to final dimensions
            {
               if(!html) el.hide();
               el.css(def);
               if ( $.isFunction(callback) ) callback();
            });
      });
   };
})(jQuery);